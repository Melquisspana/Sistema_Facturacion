<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Empresa;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\GenerarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\VacacionesAnuales;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Vacaciones anuales: 15 días + 30 % desde el mes del aniversario de ingreso, una vez por
 * año de servicio. Valores esperados escritos a mano, con la cuenta al lado. Datos ficticios.
 */
class VacacionesPlanillaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
    }

    private function persona(string $nombre, string $dui, ?string $ingreso, ?string $sueldo = '408.80', bool $descuentos = true): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => $descuentos, 'alta_el' => $ingreso, 'sueldo_mensual' => $sueldo,
            'sueldo_desde' => '2018-01-01', 'confirmar_bajo_minimo' => true,
        ]);
    }

    private function datos(string $pago): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => $pago, 'clase' => 'vacaciones', 'moneda' => 'USD', 'fecha_pago' => $pago];
    }

    private function abrir(string $pago): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, $this->datos($pago));
    }

    private function generar(string $pago): Planilla
    {
        return app(GenerarPlanilla::class)->generar($this->actor, $this->datos($pago));
    }

    private function linea(Planilla $planilla, PlanillaEmpleado $empleado): ?PlanillaDetalle
    {
        return PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $empleado->id)->first();
    }

    /** Lo que manda el formulario «Editar datos» de la ficha. */
    private function formulario(PlanillaEmpleado $e, mixed $pagadasHasta): array
    {
        return [
            'nombre' => $e->nombre, 'documento_tipo' => $e->documento_tipo, 'dui' => $e->dui,
            'cargo' => $e->cargo, 'alta_el' => $e->alta_el?->toDateString(), 'vacaciones_pagadas_hasta' => $pagadasHasta,
        ];
    }

    // ── El monto ──────────────────────────────────────────────────────────

    /** @return array<string, array{0: int, 1: int}> */
    public static function montos(): array
    {
        return [
            // 408.80 ÷ 30 × 15 × 1.30 = 265.72, exacto.
            'salario mínimo' => [40880, 26572],
            // 1000.00 ÷ 30 × 19.5 = 650.00.
            'mil dólares' => [100000, 65000],
            // 455.55 × 19.5 ÷ 30 = 296.1075: sube a 296.11.
            'redondea hacia arriba' => [45555, 29611],
            // 333.33 × 19.5 ÷ 30 = 216.6645: queda en 216.66.
            'redondea hacia abajo' => [33333, 21666],
        ];
    }

    #[DataProvider('montos')]
    public function test_monto_de_vacaciones(int $mensual, int $esperado): void
    {
        $this->assertSame($esperado, app(VacacionesAnuales::class)->monto($mensual));
    }

    public function test_el_monto_sale_de_la_configuracion(): void
    {
        config(['planilla_prestaciones.vacaciones.recargo' => '0.50']);

        // 408.80 ÷ 30 × 15 × 1.50 = 306.60.
        $this->assertSame(30660, app(VacacionesAnuales::class)->monto(40880));
    }

    public function test_con_descuentos_lleva_isss_afp_y_renta_como_una_quincena(): void
    {
        // Melqui, 06/11/2026: llevan descuentos de ley.
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->abrir('2026-11-20');

        $this->assertSame('mensual', $planilla->tipo_periodo);
        $this->assertSame('2026-11', $planilla->periodo);

        $linea = $this->linea($planilla, $ana);
        $conceptos = $linea->conceptos->keyBy(fn ($c) => $c->ley ?? $c->tipo);

        $this->assertSame('Vacaciones', $conceptos['ingreso']->concepto);
        $this->assertNull($linea->observaciones);
        $this->assertSame('265.72', (string) $conceptos['ingreso']->importe);
        // ISSS 3 % de 265.72 = 7.9716 → 7.97. AFP 7.25 % = 19.2647 → 19.26.
        $this->assertSame('7.97', (string) $conceptos['isss']->importe);
        $this->assertSame('19.26', (string) $conceptos['afp']->importe);
        // Gravado 238.49: no pasa de 275.00 de la tabla quincenal, sin renta.
        $this->assertSame('0.00', (string) $conceptos['renta']->importe);
        // 265.72 − 7.97 − 19.26 = 238.49.
        $this->assertSame('238.49', (string) $linea->a_pagar);
    }

    public function test_quien_va_sin_descuentos_cobra_el_bruto(): void
    {
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);
        $linea = $this->linea($this->abrir('2026-11-20'), $bea);

        // 300.00 ÷ 30 × 19.5 = 195.00, sin ningún descuento.
        $this->assertSame('195.00', (string) $linea->a_pagar);
        $this->assertSame(1, $linea->conceptos->count());
    }

    public function test_con_el_interruptor_apagado_todos_cobran_el_bruto(): void
    {
        config(['planilla_prestaciones.vacaciones.con_descuentos_ley' => false]);
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $linea = $this->linea($this->abrir('2026-11-20'), $ana);
        $this->assertSame('265.72', (string) $linea->a_pagar);
        $this->assertSame('0.00', (string) $linea->descuentos);
        $this->assertSame(1, $linea->conceptos->count());
    }

    public function test_con_descuentos_y_renta(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10', '1000.00');
        $linea = $this->linea($this->abrir('2026-11-20'), $ana);
        $conceptos = $linea->conceptos->keyBy(fn ($c) => $c->ley ?? $c->tipo);

        $this->assertSame('650.00', (string) $conceptos['ingreso']->importe);
        // ISSS con el tope quincenal: 3 % de 500.00 = 15.00. AFP 7.25 % de 650 = 47.125 → 47.13.
        $this->assertSame('15.00', (string) $conceptos['isss']->importe);
        $this->assertSame('47.13', (string) $conceptos['afp']->importe);
        // Gravado 587.87, tramo III: 30.00 + 20 % de (587.87 − 447.62) = 30.00 + 28.05 = 58.05.
        $this->assertSame('58.05', (string) $conceptos['renta']->importe);
        // 650.00 − 15.00 − 47.13 − 58.05 = 529.82.
        $this->assertSame('529.82', (string) $linea->a_pagar);
    }

    public function test_no_es_recalculo_de_renta_aunque_sea_diciembre(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-12-10');

        // Sin renta acumulada confirmada, una quincena de diciembre frenaría; esta no.
        $this->assertSame([], app(PrepararPlanilla::class)->reparosParaConfirmar($this->abrir('2026-12-15')->fresh()));
        $this->assertNotNull($this->linea(Planilla::sole(), $ana));
    }

    // ── Quién entra ───────────────────────────────────────────────────────

    public function test_quien_entra_y_quien_no(): void
    {
        $delMes = $this->persona('Ana', 'FICTICIO-1', '2020-11-28');
        $nuevo = $this->persona('Carla', 'FICTICIO-3', '2025-12-01');
        $inactivo = $this->persona('Dora', 'FICTICIO-4', '2021-11-05');
        $inactivo->update(['activo' => false, 'baja_el' => '2026-10-01']);
        // Cumplió hace tres meses y no cobró: entra, sin dato en la ficha.
        $otroMes = $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        // Aniversario el 03/12/2025: el más viejo que todavía entra, once meses atrás.
        $onceMeses = $this->persona('Fany', 'FICTICIO-6', '2018-12-03');
        $sinIngreso = $this->persona('Gina', 'FICTICIO-7', null);

        $planilla = $this->abrir('2026-11-05');

        // El aniversario del 28 entra aunque se pague el 5: es de este mes.
        $this->assertNull($this->linea($planilla, $delMes)->observaciones);
        $this->assertNull($this->linea($planilla, $nuevo));
        $this->assertNull($this->linea($planilla, $inactivo));
        $this->assertNull($this->linea($planilla, $sinIngreso));

        $eva = $this->linea($planilla, $otroMes);
        $this->assertSame('Cumplió aniversario el 12/08/2026.', $eva->observaciones);
        $this->assertSame('Vacaciones', $eva->conceptos->firstWhere('tipo', 'ingreso')->concepto);
        $this->assertSame('Cumplió aniversario el 03/12/2025.', $this->linea($planilla, $onceMeses)->observaciones);

        // Sin fecha de ingreso no se sabe cuándo le tocan: se avisa.
        $avisos = implode(' ', app(PrepararPlanilla::class)->avisosParaConfirmar($planilla->fresh(), true));
        $this->assertStringContainsString('Gina Ficticia queda fuera: no tiene fecha de ingreso', $avisos);
        $this->assertStringNotContainsString('Carla', $avisos);
    }

    public function test_sin_el_dato_entra_aunque_haya_cumplido_hace_cinco_meses(): void
    {
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');

        $linea = $this->linea($this->abrir('2026-11-20'), $e);
        // 265.72 − 7.97 − 19.26 = 238.49.
        $this->assertSame('238.49', (string) $linea->a_pagar);
        $this->assertSame('Cumplió aniversario el 20/06/2026.', $linea->observaciones);
    }

    public function test_con_el_dato_en_el_ano_del_ultimo_aniversario_no_entra(): void
    {
        $pagada = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');
        $pagada->update(['vacaciones_pagadas_hasta' => 2026]);
        $delAnoAnterior = $this->persona('Fany', 'FICTICIO-6', '2019-06-20');
        $delAnoAnterior->update(['vacaciones_pagadas_hasta' => 2025]);

        $planilla = $this->abrir('2026-11-20');

        $this->assertNull($this->linea($planilla, $pagada));
        // Pagada hasta 2025: el aniversario de 2026 sigue por pagar.
        $this->assertNotNull($this->linea($planilla, $delAnoAnterior));
    }

    public function test_el_dato_no_tapa_el_ano_siguiente(): void
    {
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');
        $e->update(['vacaciones_pagadas_hasta' => 2026]);

        $this->assertNotNull($this->linea($this->abrir('2027-06-25'), $e));
    }

    public function test_quien_entro_un_29_de_febrero_cumple_el_28(): void
    {
        $e = $this->persona('Ana', 'FICTICIO-1', '2024-02-29');

        // Entra en febrero de 2025 como aniversario del mes: el 28.
        $this->assertNull($this->linea($this->abrir('2025-02-10'), $e)->observaciones);
    }

    public function test_quien_entro_un_29_de_febrero_y_cobra_en_marzo(): void
    {
        $e = $this->persona('Ana', 'FICTICIO-1', '2024-02-29');

        $this->assertSame('Cumplió aniversario el 28/02/2025.', $this->linea($this->abrir('2025-03-10'), $e)->observaciones);
    }

    // ── Una sola vez por año de servicio ──────────────────────────────────

    public function test_lo_ya_pagado_no_entra_y_se_paga_una_sola_vez_por_ano_de_servicio(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $noviembre = $this->generar('2026-11-20');
        $this->assertSame('confirmada', $noviembre->estado);
        $this->assertNotNull($this->linea($noviembre, $ana));

        // En diciembre ya cobró el año cumplido el 10/11/2026.
        $diciembre = $this->abrir('2026-12-10');
        $this->assertNull($this->linea($diciembre, $ana));

        // Ni agregándola a mano: frena la confirmación.
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $diciembre, [[
            'planilla_empleado_id' => $ana->id, 'salario' => '0.00',
            'conceptos' => [['tipo' => 'ingreso', 'concepto' => 'Vacaciones', 'importe' => '265.72']],
        ]]);

        $reparos = implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($diciembre->fresh()->load('detalles.conceptos', 'detalles.empleado')));
        $this->assertStringContainsString('no le tocan vacaciones en esta planilla (ya cobró las vacaciones del año cumplido el 10/11/2026, en la planilla 2026-11)', $reparos);

        $this->expectException(ValidationException::class);
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $diciembre->fresh());
    }

    public function test_agregar_a_mano_a_quien_la_ficha_da_por_pagado_frena(): void
    {
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');
        $e->update(['vacaciones_pagadas_hasta' => 2026]);
        $planilla = $this->abrir('2026-11-20');

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [[
            'planilla_empleado_id' => $e->id, 'salario' => '0.00',
            'conceptos' => [['tipo' => 'ingreso', 'concepto' => 'Vacaciones', 'importe' => '265.72']],
        ]]);

        $reparos = implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()));
        $this->assertStringContainsString('según su ficha, ya cobró las vacaciones hasta 2026', $reparos);
    }

    public function test_el_ano_siguiente_vuelve_a_tocarle(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->generar('2026-11-20');

        $this->assertNotNull($this->linea($this->abrir('2027-11-15'), $ana));
    }

    public function test_el_mismo_mes_no_se_genera_dos_veces(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->generar('2026-11-20');

        $this->expectException(ValidationException::class);
        $this->generar('2026-11-25');
    }

    public function test_si_se_anula_vuelve_a_quedar_por_pagar(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $noviembre = $this->generar('2026-11-20');
        app(AnularPlanilla::class)->anular($this->actor, $noviembre, 'Prueba de anulación');

        $this->assertSame('Cumplió aniversario el 10/11/2026.', $this->linea($this->abrir('2026-12-10'), $ana)->observaciones);
    }

    public function test_lo_pagado_otro_mes_tampoco_se_repite(): void
    {
        $eva = $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        $this->generar('2026-10-15');

        $this->assertNull($this->linea($this->abrir('2026-11-20'), $eva));
    }

    public function test_exige_fecha_de_pago(): void
    {
        $this->expectException(ValidationException::class);

        app(PrepararPlanilla::class)->abrir($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'vacaciones', 'moneda' => 'USD']);
    }

    public function test_sin_sueldo_frena_y_dice_que_falta(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10', null);

        $reparos = implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($this->abrir('2026-11-20')->fresh()));
        $this->assertStringContainsString('falta el sueldo', $reparos);
    }

    // ── Pantallas ─────────────────────────────────────────────────────────

    public function test_recibo_y_ficha_de_la_planilla(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->generar('2026-11-20');

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))
            ->assertOk()->assertSee('Vacaciones')->assertSee('265.72');
        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()->assertSee('2026-11 · Vacaciones');
    }

    public function test_la_revision_dice_cuando_cumplio(): void
    {
        $this->persona('Eva', 'FICTICIO-5', '2019-08-12');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->datos('2026-11-20')))
            ->assertOk()->assertSee('Cumplió aniversario el 12/08/2026.')->assertDontSee('atrasad');
        $this->assertSame(0, Planilla::count());
    }

    public function test_aviso_del_inicio_y_enlace(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->persona('Bea', 'FICTICIO-2', '2021-11-25', '300.00', false);
        $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');
        $this->persona('Dora', 'FICTICIO-4', '2019-06-20')->update(['vacaciones_pagadas_hasta' => 2026]);

        // Un enlace discreto junto al botón: sin caja de color ni negritas.
        $html = $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()
            ->assertSee('Vacaciones por pagar (3)</a>', false)
            ->assertSee('href="'.route('planilla.create', ['clase' => 'vacaciones']).'"', false)
            ->assertDontSee('atrasad')
            ->assertDontSee('bg-amber-50')
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/<strong>\s*3\s*<\/strong>/', $html);

        $this->generar('2026-11-06');

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()->assertDontSee('Vacaciones por pagar');
    }

    public function test_el_recibo_de_vacaciones_dice_que_es_de_vacaciones_y_el_ano_de_servicio(): void
    {
        // Sin seguimiento de pagos, el papel dice lo que recibe cada uno.
        config(['planilla.pagos_enabled' => false]);
        Empresa::create(['razon_social' => 'Patrona Ejemplo', 'nombre_comercial' => 'Negocio Ejemplo', 'nit' => '00000000000000', 'ambiente' => '00', 'activo' => true]);
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->generar('2026-11-20');

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de vacaciones')
            ->assertSeeInOrder(['Recibí de Patrona Ejemplo', 'la cantidad de US$', '238.49', 'en concepto de vacaciones anuales remuneradas:',
                '15 días de salario más 30 % de recargo, correspondientes al año de servicio del', '10/11/2025 al 10/11/2026.'])
            // Bruto, ISSS, AFP y neto, como los demás recibos. La renta en cero no se imprime.
            ->assertSeeInOrder(['Vacaciones', '265.72', 'ISSS', '7.97', 'AFP', '19.26', '238.49'])
            ->assertSee('Período de goce: del')
            ->assertSee('Recibí conforme');
    }

    public function test_el_recibo_de_una_quincena_no_cambia(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $quincena = app(GenerarPlanilla::class)->generar($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD',
        ]);

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $quincena, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de pago')->assertSee('Período pagado')
            ->assertDontSee('vacaciones anuales')->assertDontSee('Período de goce')->assertDontSee('Año de servicio');
    }

    public function test_sin_nadie_por_pagar_no_hay_aviso(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()->assertDontSee('Vacaciones por pagar');
    }

    public function test_el_enlace_abre_el_formulario_con_vacaciones_elegida(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));

        $this->actingAs($this->actor)->get(route('planilla.create', ['clase' => 'vacaciones']))->assertOk()
            ->assertSee('<option value="vacaciones" selected', false)
            ->assertSee('value="2026-11-06"', false);
    }

    // ── «Vacaciones pagadas hasta» en la ficha ────────────────────────────

    public function test_la_ficha_muestra_y_guarda_el_dato_con_bitacora(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Vacaciones pagadas hasta (año)')
            ->assertSee('Último año en que cobró sus vacaciones, aunque haya sido fuera del sistema.');

        $this->actingAs($this->actor)->post(route('planilla.empleados.datos', $e), $this->formulario($e, '2026'))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2026, $e->fresh()->vacaciones_pagadas_hasta);
        $this->assertTrue(Activity::where('subject_id', $e->id)->where('description', 'editó los datos del empleado')->exists());

        // Vacío lo borra.
        $this->actingAs($this->actor)->post(route('planilla.empleados.datos', $e), $this->formulario($e, ''))->assertSessionHasNoErrors();
        $this->assertNull($e->fresh()->vacaciones_pagadas_hasta);
    }

    /** @return array<string, array{0: mixed}> */
    public static function anosInvalidos(): array
    {
        return [
            'antes del ingreso' => ['2018'],
            'futuro' => ['2027'],
            'no es un año' => ['dos mil'],
            'con decimales' => ['2025.5'],
        ];
    }

    #[DataProvider('anosInvalidos')]
    public function test_valida_el_rango_del_ano(mixed $valor): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');

        $this->actingAs($this->actor)->post(route('planilla.empleados.datos', $e), $this->formulario($e, $valor))
            ->assertSessionHasErrors('vacaciones_pagadas_hasta');
        $this->assertNull($e->fresh()->vacaciones_pagadas_hasta);
    }

    public function test_los_bordes_del_rango_valen(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');

        foreach (['2019', '2026'] as $valor) {
            $this->actingAs($this->actor)->post(route('planilla.empleados.datos', $e), $this->formulario($e, $valor))->assertSessionHasNoErrors();
            $this->assertSame((int) $valor, $e->fresh()->vacaciones_pagadas_hasta);
        }
    }

    public function test_sin_permiso_de_gestionar_no_cambia_el_dato(): void
    {
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');
        $ver = User::factory()->create(['activo' => true]);
        $ver->syncPermissions(['planilla.ver', 'planilla.salarios']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($ver)->post(route('planilla.empleados.datos', $e), $this->formulario($e, '2026'))->assertForbidden();
        $this->assertNull($e->fresh()->vacaciones_pagadas_hasta);
    }

    // ── Permisos de la planilla ───────────────────────────────────────────

    public function test_permisos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        // Solo ver: el enlace es para generar, así que no lo ve, y no puede generar.
        $ver = User::factory()->create(['activo' => true]);
        $ver->syncPermissions(['planilla.ver']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($ver)->get(route('planilla.index'))->assertOk()->assertDontSee('Vacaciones por pagar');
        $this->actingAs($ver)->get(route('planilla.create', ['clase' => 'vacaciones']))->assertForbidden();
        $this->actingAs($ver)->get(route('planilla.revisar', $this->datos('2026-11-06')))->assertForbidden();
        $this->actingAs($ver)->post(route('planilla.store'), $this->datos('2026-11-06'))->assertForbidden();

        // Gestionar sin ver salarios: tampoco ve los montos de la revisión.
        $sinSalarios = User::factory()->create(['activo' => true]);
        $sinSalarios->syncPermissions(['planilla.ver', 'planilla.gestionar']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($sinSalarios)->get(route('planilla.revisar', $this->datos('2026-11-06')))->assertForbidden();

        // Sin el permiso de entrada, ni el inicio.
        $nadie = User::factory()->create(['activo' => true]);
        $this->actingAs($nadie)->get(route('planilla.index'))->assertForbidden();

        $this->assertSame(0, Planilla::count());
    }

    public function test_con_el_modulo_apagado_no_existe(): void
    {
        config(['planilla.enabled' => false]);

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertNotFound();
    }
}
