<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Empresa;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\BorrarPlanilla;
use App\Services\Planilla\GenerarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\VacacionesAnuales;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Vacaciones anuales: 15 días + 30 % desde el mes del aniversario de ingreso, una vez por
 * año de servicio, y de A UNA PERSONA por pago. Valores esperados escritos a mano, con la
 * cuenta al lado. Datos ficticios.
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

    /** Lo que manda el botón «Pagar vacaciones» de un renglón. */
    private function datos(string $pago, PlanillaEmpleado $e): array
    {
        return ['tipo_periodo' => 'mensual', 'fecha' => $pago, 'clase' => 'vacaciones', 'moneda' => 'USD', 'fecha_pago' => $pago, 'empleado' => $e->id];
    }

    private function abrir(string $pago, PlanillaEmpleado $e): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, $this->datos($pago, $e));
    }

    private function generar(string $pago, PlanillaEmpleado $e): Planilla
    {
        return app(GenerarPlanilla::class)->generar($this->actor, $this->datos($pago, $e));
    }

    private function linea(Planilla $planilla, PlanillaEmpleado $empleado): ?PlanillaDetalle
    {
        return PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $empleado->id)->first();
    }

    /** @return array<int, string> nombres de quienes tienen vacaciones por pagar */
    private function porPagar(string $pago): array
    {
        return array_map(fn (array $f) => $f['empleado']->nombre, app(VacacionesAnuales::class)->porPagar(CarbonImmutable::parse($pago)));
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
        $planilla = $this->abrir('2026-11-20', $ana);

        $this->assertSame($ana->id, (int) $planilla->individual_de);

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
        $linea = $this->linea($this->abrir('2026-11-20', $bea), $bea);

        // 300.00 ÷ 30 × 19.5 = 195.00, sin ningún descuento.
        $this->assertSame('195.00', (string) $linea->a_pagar);
        $this->assertSame(1, $linea->conceptos->count());
    }

    public function test_con_el_interruptor_apagado_todos_cobran_el_bruto(): void
    {
        config(['planilla_prestaciones.vacaciones.con_descuentos_ley' => false]);
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $linea = $this->linea($this->abrir('2026-11-20', $ana), $ana);
        $this->assertSame('265.72', (string) $linea->a_pagar);
        $this->assertSame('0.00', (string) $linea->descuentos);
        $this->assertSame(1, $linea->conceptos->count());
    }

    public function test_con_descuentos_y_renta(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10', '1000.00');
        $linea = $this->linea($this->abrir('2026-11-20', $ana), $ana);
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

    public function test_el_neto_estimado_de_la_lista_coincide_con_el_pago(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10', '1000.00');
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);

        $filas = collect(app(VacacionesAnuales::class)->porPagar(CarbonImmutable::parse('2026-11-20')))->keyBy(fn ($f) => $f['empleado']->id);

        $this->assertSame(65000, $filas[$ana->id]['bruto']);
        $this->assertSame(52982, $filas[$ana->id]['neto']);
        $this->assertSame(19500, $filas[$bea->id]['neto']);
        $this->assertSame('529.82', (string) $this->linea($this->abrir('2026-11-20', $ana), $ana)->a_pagar);
    }

    public function test_no_es_recalculo_de_renta_aunque_sea_diciembre(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-15 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-12-10');

        // Sin renta acumulada confirmada, una quincena de diciembre frenaría; esta no.
        $this->assertSame([], app(PrepararPlanilla::class)->reparosParaConfirmar($this->abrir('2026-12-15', $ana)->fresh()));
    }

    // ── Quién tiene vacaciones por pagar ──────────────────────────────────

    public function test_quien_esta_por_pagar_y_quien_no(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-28');
        $this->persona('Carla', 'FICTICIO-3', '2025-12-01');
        $this->persona('Dora', 'FICTICIO-4', '2021-11-05')->update(['activo' => false, 'baja_el' => '2026-10-01']);
        // Cumplió hace tres meses y no cobró: está, sin dato en la ficha.
        $eva = $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        // Aniversario el 03/12/2025: el más viejo que todavía está, once meses atrás.
        $fany = $this->persona('Fany', 'FICTICIO-6', '2018-12-03');
        $this->persona('Gina', 'FICTICIO-7', null);

        // El aniversario del 28 cuenta aunque se pague el 5: es de este mes.
        $this->assertSame(['Ana Ficticia', 'Eva Ficticia', 'Fany Ficticia'], $this->porPagar('2026-11-05'));

        $this->assertSame('Cumplió aniversario el 12/08/2026.', $this->linea($this->abrir('2026-11-05', $eva), $eva)->observaciones);
        $this->assertSame('Cumplió aniversario el 03/12/2025.', $this->linea($this->abrir('2026-11-05', $fany), $fany)->observaciones);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: bool, 3: string}> */
    public static function sinVacaciones(): array
    {
        return [
            'menos de un año' => ['2025-12-01', null, true, 'todavía no cumple un año'],
            'sin fecha de ingreso' => ['', null, true, 'no tiene fecha de ingreso'],
            'pagadas hasta este año' => ['2019-06-20', '2026', true, 'según su ficha, ya cobró las vacaciones hasta 2026'],
            'de baja' => ['2019-06-20', null, false, 'no está activa en planilla'],
        ];
    }

    #[DataProvider('sinVacaciones')]
    public function test_no_se_le_puede_pagar_a_quien_no_las_tiene_por_pagar(string $ingreso, ?string $pagadas, bool $activa, string $motivo): void
    {
        $e = $this->persona('Eva', 'FICTICIO-5', $ingreso === '' ? null : $ingreso);
        $e->update(['vacaciones_pagadas_hasta' => $pagadas, 'activo' => $activa, 'baja_el' => $activa ? null : '2026-09-30']);

        try {
            $this->abrir('2026-11-20', $e);
            $this->fail('Tenía que rechazarlo.');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('Eva Ficticia no tiene vacaciones por pagar: '.$motivo, $ex->errors()['empleado'][0]);
        }

        $this->assertSame(0, Planilla::count());
    }

    public function test_sin_el_dato_esta_por_pagar_aunque_haya_cumplido_hace_cinco_meses(): void
    {
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');

        $linea = $this->linea($this->abrir('2026-11-20', $e), $e);
        // 265.72 − 7.97 − 19.26 = 238.49.
        $this->assertSame('238.49', (string) $linea->a_pagar);
        $this->assertSame('Cumplió aniversario el 20/06/2026.', $linea->observaciones);
    }

    public function test_con_el_dato_en_el_ano_del_ultimo_aniversario_no_esta(): void
    {
        $this->persona('Eva', 'FICTICIO-5', '2019-06-20')->update(['vacaciones_pagadas_hasta' => 2026]);
        // Pagada hasta 2025: el aniversario de 2026 sigue por pagar.
        $this->persona('Fany', 'FICTICIO-6', '2019-06-20')->update(['vacaciones_pagadas_hasta' => 2025]);

        $this->assertSame(['Fany Ficticia'], $this->porPagar('2026-11-20'));
    }

    public function test_el_dato_no_tapa_el_ano_siguiente(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-06-25 09:00'));
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');
        $e->update(['vacaciones_pagadas_hasta' => 2026]);

        $this->assertNotNull($this->linea($this->abrir('2027-06-25', $e), $e));
    }

    public function test_quien_entro_un_29_de_febrero_cumple_el_28(): void
    {
        $e = $this->persona('Ana', 'FICTICIO-1', '2024-02-29');

        // En febrero de 2025 es aniversario del mes: el 28.
        $this->assertNull($this->linea($this->abrir('2025-02-10', $e), $e)->observaciones);
    }

    public function test_quien_entro_un_29_de_febrero_y_cobra_en_marzo(): void
    {
        $e = $this->persona('Ana', 'FICTICIO-1', '2024-02-29');

        $this->assertSame('Cumplió aniversario el 28/02/2025.', $this->linea($this->abrir('2025-03-10', $e), $e)->observaciones);
    }

    // ── Una persona por pago, una vez por año de servicio ─────────────────

    public function test_dos_pagos_individuales_el_mismo_mes(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);

        $deAna = $this->generar('2026-11-20', $ana);
        $deBea = $this->generar('2026-11-25', $bea);

        $this->assertNotSame($deAna->id, $deBea->id);
        $this->assertSame($deAna->periodo, $deBea->periodo);
        $this->assertSame(['confirmada', 'confirmada'], [$deAna->estado, $deBea->estado]);
        $this->assertSame([$ana->id], $deAna->detalles()->pluck('planilla_empleado_id')->all());
        $this->assertSame([$bea->id], $deBea->detalles()->pluck('planilla_empleado_id')->all());
        $this->assertSame([], $this->porPagar('2026-11-26'));
    }

    public function test_el_mismo_pago_no_se_genera_dos_veces(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->generar('2026-11-20', $ana);

        try {
            $this->generar('2026-11-25', $ana);
            $this->fail('Tenía que rechazarlo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Ese pago de vacaciones de Ana Ficticia ya está hecho. Si hay que corregirlo, anulalo y pagalo de nuevo.', $e->errors()['periodo'][0]);
        }

        $this->assertSame(1, Planilla::count());
    }

    public function test_lo_ya_pagado_no_se_paga_otra_vez_en_otro_mes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->generar('2026-11-20', $ana);

        // En diciembre ya cobró el año cumplido el 10/11/2026.
        $this->assertSame([], $this->porPagar('2026-12-10'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ya cobró las vacaciones del año cumplido el 10/11/2026, en el pago del 20/11/2026');
        $this->abrir('2026-12-10', $ana);
    }

    public function test_un_pago_de_vacaciones_es_de_una_sola_persona(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);
        $planilla = $this->abrir('2026-11-20', $ana);
        $linea = fn (PlanillaEmpleado $e) => ['planilla_empleado_id' => $e->id, 'salario' => '0.00',
            'conceptos' => [['tipo' => 'ingreso', 'concepto' => 'Vacaciones', 'importe' => '195.00']]];

        // Ni agregar a otra persona a mano, ni cambiarla por otra.
        foreach ([[$linea($ana), $linea($bea)], [$linea($bea)]] as $lineas) {
            try {
                app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, $lineas);
                $this->fail('Tenía que rechazarlo.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Un pago de vacaciones es de una sola persona', $e->errors()['lineas'][0]);
            }
        }

        $this->assertSame([$ana->id], $planilla->detalles()->pluck('planilla_empleado_id')->all());
    }

    public function test_sin_persona_no_hay_pago_de_vacaciones(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Las vacaciones se pagan de a una persona');
        app(PrepararPlanilla::class)->abrir($this->actor, ['tipo_periodo' => 'mensual', 'fecha' => '2026-11-20', 'clase' => 'vacaciones', 'moneda' => 'USD', 'fecha_pago' => '2026-11-20']);
    }

    public function test_el_formulario_generico_no_genera_vacaciones(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $generico = ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'vacaciones', 'moneda' => 'USD', 'fecha_pago' => '2026-11-20'];

        $this->actingAs($this->actor)->from(route('planilla.create'))->get(route('planilla.revisar', $generico))
            ->assertRedirect(route('planilla.create'))
            ->assertSessionHasErrors(['empleado' => 'Las vacaciones se pagan de a una persona: elegila en «Pagar vacaciones».']);
        $this->actingAs($this->actor)->post(route('planilla.store'), $generico)->assertSessionHasErrors('empleado');

        $this->assertSame(0, Planilla::count());
    }

    public function test_agregar_a_mano_en_la_revision_solo_admite_a_su_persona(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);
        // Un borrador que quedó de antes: el único lugar donde se agregan personas a mano.
        $planilla = $this->abrir('2026-11-20', $ana);

        $this->actingAs($this->actor)->get(route('planilla.preparar', $planilla))->assertOk()
            ->assertSee('Pago de vacaciones de Ana Ficticia')
            ->assertSee('Un pago de vacaciones es de una sola persona. Para pagarle a otra, usá')
            ->assertDontSee('Bea Ficticia')
            // «quincena» solo queda en un comentario del código de la página, que no se ve.
            ->assertDontSee('Período trabajado')->assertDontSee('esta quincena')->assertDontSee('Mensual');

        $linea = fn (PlanillaEmpleado $e) => ['planilla_empleado_id' => $e->id, 'salario' => '0.00',
            'conceptos' => [['tipo' => 'ingreso', 'concepto' => 'Vacaciones', 'importe' => '195.00']]];

        $this->actingAs($this->actor)->put(route('planilla.guardar', $planilla), ['lineas' => [$linea($ana), $linea($bea)]])
            ->assertSessionHasErrors(['lineas' => 'Un pago de vacaciones es de una sola persona. Para pagarle a otra, usá «Pagar vacaciones».']);

        $this->assertSame([$ana->id], $planilla->detalles()->pluck('planilla_empleado_id')->all());
        $this->assertSame('borrador', $planilla->fresh()->estado);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function fechasInvalidas(): array
    {
        return [
            'sin fecha' => ['', 'fecha_pago', 'Indicá la fecha de pago.'],
            'al revés' => ['20/11/2026', 'fecha_pago', 'La fecha de pago no es una fecha válida.'],
            'no existe' => ['2026-02-30', 'fecha_pago', 'La fecha de pago no es una fecha válida.'],
            'antes del primer año' => ['2021-10-15', 'empleado', 'Ana Ficticia no tiene vacaciones por pagar: todavía no cumple un año.'],
            // Hoy es 06/11/2026: noviembre de 2027 pagaría un año que todavía no se trabajó.
            'un año que no se trabajó' => ['2027-11-15', 'fecha_pago', 'La fecha de pago no puede ser de un mes que todavía no llegó.'],
            // El año ya se cumplió, pero diciembre todavía no llegó: lo mismo que el `max` del campo.
            'un mes que no llegó' => ['2026-12-10', 'fecha_pago', 'La fecha de pago no puede ser de un mes que todavía no llegó.'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function test_valida_la_fecha_de_pago(string $fecha, string $campo, string $mensaje): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $this->actingAs($this->actor)->from(route('planilla.vacaciones'))
            ->get(route('planilla.revisar', ['fecha_pago' => $fecha] + $this->datos('2026-11-06', $ana)))
            ->assertRedirect(route('planilla.vacaciones'))
            ->assertSessionHasErrors([$campo => $mensaje]);

        $this->assertSame(0, Planilla::count());
    }

    public function test_al_volver_de_un_error_la_fecha_escrita_se_conserva(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $this->actingAs($this->actor)->from(route('planilla.vacaciones'))->followingRedirects()
            ->get(route('planilla.revisar', ['fecha_pago' => '2021-10-15'] + $this->datos('2026-11-06', $ana)))->assertOk()
            ->assertSee('No se generó ningún pago.')
            ->assertSee('todavía no cumple un año')
            ->assertSee('id="fecha-pago-'.$ana->id.'" name="fecha_pago" type="date" value="2021-10-15"', false);
    }

    public function test_el_ano_que_se_cumple_este_mes_si_se_paga_y_el_campo_tiene_tope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        // Cumple el 28: todavía no llegó, pero es de este mes.
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-28');

        $this->assertSame('confirmada', $this->generar('2026-11-06', $ana)->estado);

        $this->persona('Bea', 'FICTICIO-2', '2021-11-25', '300.00', false);
        $this->actingAs($this->actor)->get(route('planilla.vacaciones'))->assertOk()
            ->assertSee('max="2026-11-30"', false);
    }

    // ── Un pago anulado deja pagar de nuevo ───────────────────────────────

    public function test_un_pago_anulado_se_puede_volver_a_pagar_el_mismo_mes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $primero = $this->generar('2026-11-20', $ana);
        app(AnularPlanilla::class)->anular($this->actor, $primero, 'Se pagó con el sueldo equivocado');

        // Queda guardado, con su persona, pero ya no ocupa el lugar del mes.
        $this->assertSame('2026-11-A'.$primero->id, $primero->fresh()->periodo);
        $this->assertSame(['Ana Ficticia'], $this->porPagar('2026-11-20'));

        // La revisión ya no rebota al anulado: muestra el pago nuevo.
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->datos('2026-11-25', $ana)))->assertOk()
            ->assertSee('Pago de vacaciones de Ana Ficticia')->assertSee('Sí, generar el pago');

        $segundo = $this->generar('2026-11-25', $ana);
        $this->assertNotSame($primero->id, $segundo->id);
        $this->assertSame(['anulada', 'confirmada'], [$primero->fresh()->estado, $segundo->estado]);
        $this->assertSame('2026-11', $segundo->periodo);

        // Con el segundo vigente, un tercero no.
        try {
            $this->generar('2026-11-26', $ana);
            $this->fail('Tenía que rechazarlo.');
        } catch (ValidationException $e) {
            $mensaje = $e->errors()['periodo'][0];
            $this->assertSame('Ese pago de vacaciones de Ana Ficticia ya está hecho. Si hay que corregirlo, anulalo y pagalo de nuevo.', $mensaje);
            $this->assertStringNotContainsString('de ese mes', $mensaje);
            $this->assertStringNotContainsString('borrar', $mensaje);
        }

        $this->assertSame(2, Planilla::count());
    }

    public function test_anular_una_quincena_no_cambia_su_periodo(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $quincena = app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD']);
        app(AnularPlanilla::class)->anular($this->actor, $quincena, 'Prueba de anulación');

        $this->assertSame('2026-11-Q1', $quincena->fresh()->periodo);

        // Sigue ocupando su lugar: para hacerla de nuevo hay que borrarla, como siempre.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('La planilla 2026-11-Q1 ya existe (anulada)');
        app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD']);
    }

    public function test_si_otra_pantalla_lo_genero_a_la_vez_es_un_error_que_se_entiende(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        // Lo que dejaría otra pestaña que ganó la carrera: el mismo lugar de la clave única.
        // Anulado y con su período de antes, así que la comprobación previa no lo ve.
        Planilla::create([
            'clave' => 'otra-pestana', 'tipo_periodo' => 'mensual', 'periodo' => '2026-11', 'clase' => 'vacaciones',
            'individual_de' => $ana->id, 'desde' => '2026-11-01', 'hasta' => '2026-11-30', 'fecha_pago' => '2026-11-20',
            'moneda' => 'USD', 'estado' => 'anulada', 'registrado_por' => $this->actor->id,
        ]);

        $this->actingAs($this->actor)->from(route('planilla.vacaciones'))->post(route('planilla.store'), $this->datos('2026-11-20', $ana))
            ->assertRedirect(route('planilla.vacaciones'))
            ->assertSessionHasErrors(['periodo' => 'Ese pago de vacaciones se acaba de generar desde otra pantalla. Buscalo en la lista de Planillas antes de intentarlo de nuevo.']);

        $this->assertSame(1, Planilla::count());
    }

    public function test_la_persona_se_bloquea_antes_de_leer_nada(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        // Los permisos ya cargados, como en la pantalla.
        $this->actor->can('planilla.salarios');

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        foreach (['revisar', 'generar'] as $paso) {
            $consultas = [];
            app(GenerarPlanilla::class)->{$paso}($this->actor, $this->datos('2026-11-20', $ana));

            // MySQL toma la foto de lo que lee en la primera lectura de la transacción:
            // la primera tiene que ser el bloqueo de la persona.
            $this->assertStringContainsString('from "planilla_empleados" where "planilla_empleados"."id" = ?', $consultas[0] ?? '', $paso);
        }
    }

    // ── El pago guarda su año de servicio ─────────────────────────────────

    public function test_el_recibo_confirmado_no_cambia_si_se_corrige_la_fecha_de_ingreso(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        config(['planilla.pagos_enabled' => false]);
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->generar('2026-11-20', $ana);

        $linea = $this->linea($planilla, $ana);
        $this->assertSame(['2025-11-10', '2026-11-10'], [$linea->periodo_desde->toDateString(), $linea->periodo_hasta->toDateString()]);

        // Se corrige la ficha después de firmado: el papel sigue diciendo lo que se pagó.
        $ana->update(['alta_el' => '2021-03-01']);

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de vacaciones')
            ->assertSee('correspondientes al año de servicio del 10/11/2025 al 10/11/2026.')
            ->assertDontSee('01/03/2025');
        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()
            ->assertSee('Año de servicio: del 10/11/2025 al 10/11/2026');
        $this->actingAs($this->actor)->get(route('planilla.impresos', $planilla))->assertOk()
            ->assertSee('10/11/25–10/11/26');
    }

    public function test_corregir_la_fecha_de_ingreso_hacia_adelante_no_paga_dos_veces_el_ano(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-03-15');
        $this->generar('2026-03-20', $ana);

        // Se corrige: entró el 20/04. El año que termina el 20/04/2026 se cruza once meses
        // con el que ya cobró (hasta el 15/03/2026): es el mismo, no se paga otra vez.
        $ana->update(['alta_el' => '2020-04-20']);
        $this->travelTo(CarbonImmutable::parse('2026-04-25 09:00'));
        $this->assertSame([], $this->porPagar('2026-04-25'));

        try {
            $this->abrir('2026-04-25', $ana);
            $this->fail('Tenía que rechazarlo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya cobró las vacaciones del año cumplido el 20/04/2026, en el pago del 20/03/2026', $e->errors()['empleado'][0]);
        }

        // Y el año siguiente no se pierde.
        $this->travelTo(CarbonImmutable::parse('2027-04-25 09:00'));
        $this->assertSame(['Ana Ficticia'], $this->porPagar('2027-04-25'));
        $this->assertSame('confirmada', $this->generar('2027-04-25', $ana)->estado);
    }

    public function test_corregir_la_fecha_de_ingreso_hacia_atras_no_da_por_pagado_un_ano_que_no_se_pago(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-25 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-04-20');
        $this->generar('2026-04-25', $ana);

        // Se corrige: entró el 15/03. El año que termina el 15/03/2026 es el que ya cobró.
        $ana->update(['alta_el' => '2020-03-15']);
        $this->assertSame([], $this->porPagar('2026-04-25'));

        // El que termina el 15/03/2027 no se pagó (el cobrado terminó el 20/04/2026): le toca.
        $this->travelTo(CarbonImmutable::parse('2027-03-20 09:00'));
        $this->assertSame(['Ana Ficticia'], $this->porPagar('2027-03-20'));
        $nuevo = $this->generar('2027-03-20', $ana);
        $this->assertSame(['2026-03-15', '2027-03-15'], [$this->linea($nuevo, $ana)->periodo_desde->toDateString(), $this->linea($nuevo, $ana)->periodo_hasta->toDateString()]);
    }

    public function test_el_ano_de_servicio_no_sale_del_formulario(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $borrador = $this->abrir('2026-11-20', $ana);
        $conceptos = $this->linea($borrador, $ana)->conceptos->map(fn ($c) => [
            'tipo' => $c->tipo, 'ley' => $c->ley, 'concepto' => $c->concepto, 'importe' => (string) $c->importe,
            'destino' => $c->destino, 'tercero' => $c->tercero,
        ])->all();
        // Un POST a mano que trae otro año.
        $forjada = ['planilla_empleado_id' => $ana->id, 'salario' => '0.00', 'periodo_desde' => '2019-01-01', 'periodo_hasta' => '2019-12-31', 'conceptos' => $conceptos];

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $borrador, [$forjada]);
        $this->assertNull($this->linea($borrador, $ana)->periodo_desde);
        $this->assertNull($this->linea($borrador, $ana)->periodo_hasta);

        // Un borrador viejo que ya tenía fechas: al confirmar se escribe el calculado.
        $this->linea($borrador, $ana)->update(['periodo_desde' => '2019-01-01', 'periodo_hasta' => '2019-12-31']);
        $this->actingAs($this->actor)->post(route('planilla.confirmar', $borrador))->assertSessionHasNoErrors();

        $linea = $this->linea($borrador, $ana);
        $this->assertSame('confirmada', $borrador->fresh()->estado);
        $this->assertSame(['2025-11-10', '2026-11-10'], [$linea->periodo_desde->toDateString(), $linea->periodo_hasta->toDateString()]);
    }

    public function test_el_formato_del_recibo_sale_de_la_clase_y_no_de_la_fecha(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        config(['planilla.pagos_enabled' => false]);
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->generar('2026-11-20', $ana);
        // Sin el año escrito ni fecha de ingreso de donde sacarlo: sigue siendo de vacaciones.
        $this->linea($planilla, $ana)->update(['periodo_desde' => null, 'periodo_hasta' => null]);
        $ana->update(['alta_el' => null]);

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de vacaciones')->assertSee('Firma y sello de la empleadora')
            ->assertSee('año de servicio sin fecha de ingreso')
            ->assertDontSee('Recibo de pago')->assertDontSee('Período pagado');
    }

    public function test_en_gastos_el_pago_no_lleva_el_mes_tecnico(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->generar('2026-11-20', $ana);

        $deAna = Gasto::findOrFail($this->linea($planilla, $ana)->gasto_id);
        $this->assertSame('Vacaciones · Ana Ficticia', $deAna->concepto);
        $this->assertNull($deAna->periodo_desde);
        $this->assertNull($deAna->periodo_hasta);
        $this->assertTrue(Gasto::where('concepto', 'Descuentos de vacaciones de Ana Ficticia')->whereNull('periodo_desde')->exists());

        // Gastos lo muestra sin período.
        config(['gastos.enabled' => true]);
        $this->actingAs($this->actor)->get(route('gastos.show', $deAna))->assertOk()
            ->assertSee('Vacaciones · Ana Ficticia')->assertDontSee('Período:');
    }

    public function test_un_pago_de_antes_de_varias_personas_no_muestra_el_mes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);
        $planilla = $this->abrir('2026-11-20', $ana);
        // Como quedaban antes de los pagos individuales: varias personas y `individual_de` en 0.
        $this->linea($planilla, $ana)->replicate()->fill(['planilla_empleado_id' => $bea->id, 'nombre_snapshot' => 'Bea Ficticia'])->save();
        $planilla->update(['individual_de' => 0]);
        $planilla = $planilla->fresh();

        $this->assertSame('Pago de vacaciones de 2 personas', $planilla->nombreDeVacaciones());
        $this->assertSame('Vacaciones de varias personas, cada una con su año de servicio', $planilla->periodoEnPalabras());
        $this->assertNull($planilla->anioDeServicio());
    }

    public function test_si_la_ficha_lo_da_por_pagado_despues_de_abrir_frena(): void
    {
        $e = $this->persona('Eva', 'FICTICIO-5', '2019-06-20');
        $planilla = $this->abrir('2026-11-20', $e);
        $e->update(['vacaciones_pagadas_hasta' => 2026]);

        $reparos = implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($planilla->fresh()));
        $this->assertStringContainsString('según su ficha, ya cobró las vacaciones hasta 2026', $reparos);
    }

    public function test_el_ano_siguiente_vuelve_a_tocarle(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-11-15 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->generar('2026-11-20', $ana);

        $this->assertNotNull($this->linea($this->abrir('2027-11-15', $ana), $ana));
    }

    public function test_si_se_anula_vuelve_a_quedar_por_pagar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $noviembre = $this->generar('2026-11-20', $ana);
        app(AnularPlanilla::class)->anular($this->actor, $noviembre, 'Prueba de anulación');

        $this->assertSame('Cumplió aniversario el 10/11/2026.', $this->linea($this->abrir('2026-12-10', $ana), $ana)->observaciones);
    }

    public function test_exige_fecha_de_pago(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $this->expectException(ValidationException::class);
        app(PrepararPlanilla::class)->abrir($this->actor, ['tipo_periodo' => 'mensual', 'fecha' => '2026-11-01', 'clase' => 'vacaciones', 'moneda' => 'USD', 'empleado' => $ana->id]);
    }

    public function test_sin_sueldo_frena_y_dice_que_falta(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10', null);

        $reparos = implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($this->abrir('2026-11-20', $ana)->fresh()));
        $this->assertStringContainsString('falta el sueldo', $reparos);
    }

    // ── Pantallas ─────────────────────────────────────────────────────────

    public function test_la_pantalla_pagar_vacaciones_lista_a_cada_persona_con_su_boton(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $ana->update(['cargo' => 'Vendedora']);
        $this->persona('Bea', 'FICTICIO-2', '2021-11-25', '300.00', false);
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');

        $this->actingAs($this->actor)->get(route('planilla.vacaciones'))->assertOk()
            ->assertSeeInOrder(['Ana Ficticia', 'Vendedora', 'del 10/11/2025 al 10/11/2026', '265.72', '238.49', 'Pagar vacaciones'])
            ->assertSeeInOrder(['Bea Ficticia', 'del 25/11/2025 al 25/11/2026', 'Sin descuentos de ley', '195.00', '195.00'])
            ->assertSee('name="empleado" value="'.$ana->id.'"', false)
            ->assertSee('name="fecha_pago" type="date" value="2026-11-06"', false)
            ->assertDontSee('Carla Ficticia')
            ->assertDontSee('type="checkbox"', false);
    }

    public function test_sin_nadie_por_pagar_lo_dice(): void
    {
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');

        $this->actingAs($this->actor)->get(route('planilla.vacaciones'))->assertOk()->assertSee('No hay vacaciones por pagar.');
    }

    public function test_la_revision_no_muestra_periodo_ni_quincena(): void
    {
        $eva = $this->persona('Eva', 'FICTICIO-5', '2019-08-12');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->datos('2026-11-20', $eva)))->assertOk()
            ->assertSee('Pago de vacaciones de Eva Ficticia')
            ->assertSee('Año de servicio: del 12/08/2025 al 12/08/2026')
            ->assertSee('Pago el 20/11/2026')
            ->assertSeeInOrder(['Vacaciones', '265.72', 'ISSS', '7.97', 'AFP', '19.26', 'A pagar', '238.49'])
            ->assertSee('Sí, generar el pago')
            ->assertSee('href="'.route('planilla.vacaciones').'"', false)
            ->assertDontSee('Mensual')->assertDontSee('quincena')->assertDontSee('>2026-11<', false)->assertDontSee('2026-11 ·')->assertDontSee('01/11/2026');
        $this->assertSame(0, Planilla::count());
    }

    public function test_generar_lleva_al_recibo_y_la_ficha_no_muestra_periodo(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->datos('2026-11-20', $ana));
        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))
            ->assertSessionHas('planilla.aviso', 'Pago de vacaciones de Ana Ficticia generado. Imprimí el recibo y hacelo firmar.');

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()
            ->assertSee('Pago de vacaciones de Ana Ficticia')
            ->assertSee('Año de servicio: del 10/11/2025 al 10/11/2026')
            ->assertSee('Pago el 20/11/2026')
            ->assertDontSee('Mensual')->assertDontSee('2026-11 ·')->assertDontSee('01/11/2026 al 30/11/2026');

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()
            ->assertSeeInOrder(['Pago de vacaciones de Ana Ficticia', 'Año de servicio: del 10/11/2025 al 10/11/2026 · Pago el 20/11/2026'])
            ->assertDontSee('Mensual')->assertDontSee('>2026-11<', false);

        $this->actingAs($this->actor)->get(route('planilla.impresos', $planilla))->assertOk()
            ->assertSee('Impresos · Pago de vacaciones de Ana Ficticia')
            ->assertSee('Vacaciones para firma')
            ->assertDontSee('Mensual')->assertDontSee('quincena')->assertDontSee('01/11/2026 al 30/11/2026');
    }

    public function test_el_recibo_de_vacaciones_dice_que_es_de_vacaciones_y_el_ano_de_servicio(): void
    {
        // Sin seguimiento de pagos, el papel dice lo que recibe cada uno.
        config(['planilla.pagos_enabled' => false]);
        Empresa::create(['razon_social' => 'Patrona Ejemplo', 'nombre_comercial' => 'Negocio Ejemplo', 'nit' => '00000000000000', 'ambiente' => '00', 'activo' => true]);
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $ana->update(['cargo' => 'Vendedora']);
        $planilla = $this->generar('2026-11-20', $ana);

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de vacaciones')
            ->assertSee('VAC-'.str_pad((string) $planilla->id, 5, '0', STR_PAD_LEFT))
            ->assertSee('Puesto: Vendedora')
            // Firman la persona y la empleadora; el sello va sobre la firma, sin recuadro.
            ->assertSeeInOrder(['Recibí conforme', 'Firma y sello de la empleadora', 'Patrona Ejemplo'])
            ->assertDontSee('Entregó el pago')
            ->assertSeeInOrder(['Recibí de Patrona Ejemplo', 'la cantidad de US$', '238.49', 'en concepto de vacaciones anuales remuneradas:',
                '15 días de salario más 30 % de recargo, correspondientes al año de servicio del', '10/11/2025 al 10/11/2026.'])
            // Bruto, ISSS, AFP y neto, como los demás recibos. La renta en cero no se imprime.
            ->assertSeeInOrder(['Vacaciones', '265.72', 'ISSS', '7.97', 'AFP', '19.26', '238.49'])
            ->assertSee('Período de goce: del')
            ->assertDontSee('2026-11-001');
    }

    public function test_el_recibo_de_una_quincena_no_cambia(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $quincena = app(GenerarPlanilla::class)->generar($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD',
        ]);

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $quincena, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de pago')->assertSee('Período pagado')->assertSee('Entregó el pago')
            ->assertDontSee('Firma y sello de la empleadora')
            ->assertDontSee('vacaciones anuales')->assertDontSee('Período de goce')->assertDontSee('Año de servicio');
    }

    public function test_borrar_un_pago_de_vacaciones_lo_dice_por_su_nombre(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->generar('2026-11-20', $ana);

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))
            ->assertRedirect(route('planilla.index'))
            ->assertSessionHas('planilla.aviso', 'Pago de vacaciones de Ana Ficticia borrado con todo lo que había creado. Los anticipos que descontaba volvieron a tener su saldo.');

        $this->assertSame(0, Planilla::count());
        $this->assertSame(['Ana Ficticia'], $this->porPagar('2026-11-20'));
    }

    public function test_el_pago_de_otra_persona_despues_no_impide_borrar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);
        $deAna = $this->generar('2026-11-20', $ana);
        $this->generar('2026-11-25', $bea);

        $this->assertSame([], app(BorrarPlanilla::class)->impedimentos($deAna));
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $deAna))->assertSessionHasNoErrors()->assertRedirect(route('planilla.index'));
        $this->assertNull(Planilla::find($deAna->id));
    }

    public function test_una_quincena_posterior_con_la_persona_si_impide_borrar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $deAna = $this->generar('2026-11-20', $ana);
        app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-16', 'clase' => 'regular', 'moneda' => 'USD']);

        $this->assertContains('Solo se puede borrar la última planilla, y después de esta está la 2026-11-Q2.', app(BorrarPlanilla::class)->impedimentos($deAna));
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $deAna));
        $this->assertNotNull(Planilla::find($deAna->id));
    }

    public function test_entre_quincenas_la_ultima_sigue_siendo_la_ultima(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $q1 = app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD']);
        $q2 = app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-16', 'clase' => 'regular', 'moneda' => 'USD']);

        $this->assertSame($q2->id, app(BorrarPlanilla::class)->posterior($q1)?->id);
        $this->assertNull(app(BorrarPlanilla::class)->posterior($q2));
    }

    // ── Las planillas de varios no cambian ────────────────────────────────

    public function test_la_quincena_y_la_mensual_siguen_siendo_una_por_periodo(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $quincena = ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD'];
        $mensual = ['tipo_periodo' => 'mensual', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD'];

        $this->actingAs($this->actor)->get(route('planilla.revisar', $mensual))->assertOk()
            ->assertSee('Generar la planilla 2026-11')->assertSee('Mensual')->assertSee('01/11/2026 al 30/11/2026')
            ->assertDontSee('Año de servicio');

        $primera = app(GenerarPlanilla::class)->generar($this->actor, $quincena);
        $this->assertSame(0, (int) $primera->individual_de);

        try {
            app(GenerarPlanilla::class)->generar($this->actor, $quincena);
            $this->fail('Tenía que rechazarla.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('La planilla 2026-11-Q1 ya existe', $e->errors()['periodo'][0]);
        }

        // Y la base lo sigue garantizando, aunque alguien se saltara la comprobación.
        $this->expectException(QueryException::class);
        Planilla::create(collect($primera->getAttributes())->except(['id', 'clave'])->put('clave', 'otra-clave')->all());
    }

    public function test_la_migracion_baja_y_vuelve_a_subir(): void
    {
        $migracion = require database_path('migrations/2026_11_07_100000_add_individual_de_to_planillas.php');

        $migracion->down();
        $this->assertFalse(Schema::hasColumn('planillas', 'individual_de'));

        $migracion->up();
        $this->assertTrue(Schema::hasColumn('planillas', 'individual_de'));

        // Dos pagos de vacaciones del mismo mes, de personas distintas, caben.
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);
        $this->generar('2026-11-20', $ana);
        $this->generar('2026-11-25', $bea);
        $this->assertSame(2, Planilla::where('periodo', '2026-11')->count());
    }

    public function test_si_la_migracion_no_puede_bajar_la_tabla_no_queda_sin_clave(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        $migracion = require database_path('migrations/2026_11_07_100000_add_individual_de_to_planillas.php');
        $this->generar('2026-11-20', $this->persona('Ana', 'FICTICIO-1', '2020-11-10'));
        $this->generar('2026-11-25', $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false));

        try {
            $migracion->down();
            $this->fail('Con dos pagos vigentes del mismo mes, la clave vieja no entra.');
        } catch (QueryException) {
            // Lo esperado.
        }

        $this->assertTrue(Schema::hasColumn('planillas', 'individual_de'));
        $this->assertTrue(Schema::hasIndex('planillas', ['tipo_periodo', 'periodo', 'clase', 'individual_de'], 'unique'));
    }

    public function test_textos_sin_periodo_en_la_ficha_la_lista_y_la_revision(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->generar('2026-11-20', $ana);

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$ana->id))->assertOk()
            ->assertSeeInOrder(['Últimos pagos', 'Pago', 'Pago del 20/11/2026'])
            ->assertDontSee('Últimas quincenas');
        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()
            ->assertSee('Planilla o pago')->assertDontSee('>Período</th>', false);

        $borrador = $this->abrir('2026-11-20', $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false));
        $this->actingAs($this->actor)->get(route('planilla.preparar', $borrador))->assertOk()
            ->assertSee('Pago de vacaciones')->assertDontSee('Pago del período')
            // El salario en 0 viaja oculto: el monto es el ingreso «Vacaciones».
            ->assertDontSee(":id=\"'salario-'+i\"", false)
            ->assertSee(":name=\"'lineas['+i+'][salario]'\" :value=\"l.salario\"", false);
    }

    public function test_enlace_del_inicio_a_pagar_vacaciones(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->persona('Bea', 'FICTICIO-2', '2021-11-25', '300.00', false);
        $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');
        $this->persona('Dora', 'FICTICIO-4', '2019-06-20')->update(['vacaciones_pagadas_hasta' => 2026]);

        // Un enlace discreto junto al botón: sin caja de color ni negritas.
        $html = $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()
            ->assertSee('Vacaciones por pagar (3)</a>', false)
            ->assertSee('href="'.route('planilla.vacaciones').'"', false)
            ->assertDontSee('bg-amber-50')
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/<strong>\s*3\s*<\/strong>/', $html);

        $this->generar('2026-11-06', $ana);

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()->assertSee('Vacaciones por pagar (2)</a>', false);
    }

    public function test_sin_nadie_por_pagar_no_hay_enlace(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()->assertDontSee('Vacaciones por pagar');
    }

    public function test_el_formulario_de_planilla_ya_no_ofrece_vacaciones(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.create'))->assertOk()
            ->assertDontSee('<option value="vacaciones"', false)
            ->assertSee('href="'.route('planilla.vacaciones').'"', false);
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

    // ── Permisos ──────────────────────────────────────────────────────────

    public function test_permisos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        // Solo ver: ni el enlace, ni la pantalla, ni generar.
        $ver = User::factory()->create(['activo' => true]);
        $ver->syncPermissions(['planilla.ver']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($ver)->get(route('planilla.index'))->assertOk()->assertDontSee('Vacaciones por pagar');
        $this->actingAs($ver)->get(route('planilla.vacaciones'))->assertForbidden();
        $this->actingAs($ver)->get(route('planilla.revisar', $this->datos('2026-11-06', $ana)))->assertForbidden();
        $this->actingAs($ver)->post(route('planilla.store'), $this->datos('2026-11-06', $ana))->assertForbidden();

        // Gestionar sin ver salarios: la pantalla y la revisión llevan montos.
        $sinSalarios = User::factory()->create(['activo' => true]);
        $sinSalarios->syncPermissions(['planilla.ver', 'planilla.gestionar']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($sinSalarios)->get(route('planilla.index'))->assertOk()->assertDontSee('Vacaciones por pagar');
        $this->actingAs($sinSalarios)->get(route('planilla.vacaciones'))->assertForbidden();
        $this->actingAs($sinSalarios)->get(route('planilla.revisar', $this->datos('2026-11-06', $ana)))->assertForbidden();
        $this->actingAs($sinSalarios)->post(route('planilla.store'), $this->datos('2026-11-06', $ana))->assertForbidden();

        // Con lo mismo que pide una planilla, entra y ve a quién pagarle.
        $gestiona = User::factory()->create(['activo' => true]);
        $gestiona->syncPermissions(['planilla.ver', 'planilla.gestionar', 'planilla.salarios']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($gestiona)->get(route('planilla.vacaciones'))->assertOk()->assertSee('Ana Ficticia');
        $this->actingAs($gestiona)->get(route('planilla.revisar', $this->datos('2026-11-06', $ana)))->assertOk()->assertSee('Sí, generar el pago');

        // Sin el permiso de entrada, ni el inicio.
        $nadie = User::factory()->create(['activo' => true]);
        $this->actingAs($nadie)->get(route('planilla.index'))->assertForbidden();

        $this->assertSame(0, Planilla::count());
    }

    public function test_con_el_modulo_apagado_no_existe(): void
    {
        config(['planilla.enabled' => false]);

        $this->actingAs($this->actor)->get(route('planilla.vacaciones'))->assertNotFound();
    }
}
