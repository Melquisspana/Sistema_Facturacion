<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Vacaciones anuales: 15 días + 30 % en el mes del aniversario de ingreso, con atrasados.
 * Valores esperados escritos a mano, con la cuenta al lado. Datos ficticios.
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
        config(['planilla.enabled' => true, 'gastos.enabled' => false, 'planilla_prestaciones.vacaciones.aniversarios_desde' => null]);

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
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->abrir('2026-11-20');

        $this->assertSame('mensual', $planilla->tipo_periodo);
        $this->assertSame('2026-11', $planilla->periodo);

        $linea = $this->linea($planilla, $ana);
        $conceptos = $linea->conceptos->keyBy(fn ($c) => $c->ley ?? $c->tipo);

        $this->assertSame('Vacaciones, año cumplido el 10/11/2026', $conceptos['ingreso']->concepto);
        $this->assertSame('265.72', (string) $conceptos['ingreso']->importe);
        // ISSS 3 % de 265.72 = 7.9716 → 7.97. AFP 7.25 % = 19.2647 → 19.26.
        $this->assertSame('7.97', (string) $conceptos['isss']->importe);
        $this->assertSame('19.26', (string) $conceptos['afp']->importe);
        // Gravado 238.49: no pasa de 275.00 de la tabla quincenal, sin renta.
        $this->assertSame('0.00', (string) $conceptos['renta']->importe);
        // 265.72 − 7.97 − 19.26 = 238.49.
        $this->assertSame('238.49', (string) $linea->a_pagar);
        $this->assertNull($linea->observaciones);
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

    public function test_sin_descuentos_cobra_el_bruto(): void
    {
        $bea = $this->persona('Bea', 'FICTICIO-2', '2021-11-03', '300.00', false);
        $linea = $this->linea($this->abrir('2026-11-20'), $bea);

        // 300.00 ÷ 30 × 19.5 = 195.00, sin ningún descuento.
        $this->assertSame('195.00', (string) $linea->a_pagar);
        $this->assertSame(1, $linea->conceptos->count());
    }

    public function test_si_la_configuracion_lo_dice_no_llevan_descuentos(): void
    {
        config(['planilla_prestaciones.vacaciones.con_descuentos_ley' => false]);
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        $this->assertSame('265.72', (string) $this->linea($this->abrir('2026-11-20'), $ana)->a_pagar);
    }

    public function test_quien_entra_y_quien_no(): void
    {
        $delMes = $this->persona('Ana', 'FICTICIO-1', '2020-11-28');
        $nuevo = $this->persona('Carla', 'FICTICIO-3', '2025-12-01');
        $inactivo = $this->persona('Dora', 'FICTICIO-4', '2021-11-05');
        $inactivo->update(['activo' => false, 'baja_el' => '2026-10-01']);
        $atrasado = $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        // Aniversario el 03/12/2025: el más viejo que todavía entra, once meses atrás.
        $onceMeses = $this->persona('Fany', 'FICTICIO-6', '2018-12-03');
        $sinIngreso = $this->persona('Gina', 'FICTICIO-7', null);

        $planilla = $this->abrir('2026-11-05');

        // El aniversario del 28 entra aunque se pague el 5: es de este mes.
        $this->assertNotNull($this->linea($planilla, $delMes));
        $this->assertNull($this->linea($planilla, $nuevo));
        $this->assertNull($this->linea($planilla, $inactivo));
        $this->assertNull($this->linea($planilla, $sinIngreso));

        $eva = $this->linea($planilla, $atrasado);
        $this->assertSame('Vacaciones atrasadas: cumplió aniversario el 12/08/2026.', $eva->observaciones);
        $this->assertSame('Vacaciones, año cumplido el 12/08/2026 (atrasadas)', $eva->conceptos->firstWhere('tipo', 'ingreso')->concepto);
        $this->assertSame('Vacaciones atrasadas: cumplió aniversario el 03/12/2025.', $this->linea($planilla, $onceMeses)->observaciones);

        // Sin fecha de ingreso no se sabe cuándo le tocan: se avisa.
        $avisos = implode(' ', app(PrepararPlanilla::class)->avisosParaConfirmar($planilla->fresh(), true));
        $this->assertStringContainsString('Gina Ficticia queda fuera: no tiene fecha de ingreso', $avisos);
        $this->assertStringNotContainsString('Carla', $avisos);
    }

    public function test_quien_entro_un_29_de_febrero_cumple_el_28(): void
    {
        $e = $this->persona('Ana', 'FICTICIO-1', '2024-02-29');

        $this->assertSame('Vacaciones, año cumplido el 28/02/2025', $this->linea($this->abrir('2025-02-10'), $e)->conceptos->first()->concepto);
    }

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

    public function test_el_ano_siguiente_vuelve_a_tocarle(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->generar('2026-11-20');

        $siguiente = $this->linea($this->abrir('2027-11-15'), $ana);
        $this->assertSame('Vacaciones, año cumplido el 10/11/2027', $siguiente->conceptos->firstWhere('tipo', 'ingreso')->concepto);
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

        $diciembre = $this->linea($this->abrir('2026-12-10'), $ana);
        $this->assertSame('Vacaciones atrasadas: cumplió aniversario el 10/11/2026.', $diciembre->observaciones);
    }

    public function test_un_atrasado_pagado_despues_tampoco_se_repite(): void
    {
        $eva = $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        $this->generar('2026-10-15');

        $this->assertNull($this->linea($this->abrir('2026-11-20'), $eva));
    }

    public function test_los_aniversarios_de_antes_del_sistema_no_se_proponen(): void
    {
        config(['planilla_prestaciones.vacaciones.aniversarios_desde' => '2026-11-01']);
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $eva = $this->persona('Eva', 'FICTICIO-5', '2019-08-12');

        $planilla = $this->abrir('2026-11-20');

        $this->assertNotNull($this->linea($planilla, $ana));
        $this->assertNull($this->linea($planilla, $eva));
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

    public function test_no_es_recalculo_de_renta_aunque_sea_diciembre(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1', '2020-12-10');

        // Sin renta acumulada confirmada, una quincena de diciembre frenaría; esta no.
        $this->assertSame([], app(PrepararPlanilla::class)->reparosParaConfirmar($this->abrir('2026-12-15')->fresh()));
        $this->assertNotNull($this->linea(Planilla::sole(), $ana));
    }

    public function test_recibo_y_hoja_salen_con_el_concepto(): void
    {
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $planilla = $this->generar('2026-11-20');

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))
            ->assertOk()->assertSee('Vacaciones, año cumplido el 10/11/2026');
        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()->assertSee('2026-11 · Vacaciones');
    }

    public function test_la_revision_marca_a_los_atrasados(): void
    {
        $this->persona('Eva', 'FICTICIO-5', '2019-08-12');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->datos('2026-11-20')))
            ->assertOk()->assertSee('Vacaciones atrasadas: cumplió aniversario el 12/08/2026.');
        $this->assertSame(0, Planilla::count());
    }

    public function test_aviso_del_inicio_con_atrasados_y_enlace(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');
        $this->persona('Bea', 'FICTICIO-2', '2021-11-25', '300.00', false);
        $this->persona('Eva', 'FICTICIO-5', '2019-08-12');
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()
            ->assertSeeInOrder(['Este mes cumplen aniversario:', '2', '(vacaciones por pagar)', 'Atrasados de meses anteriores:', '1'])
            ->assertSee(route('planilla.create', ['clase' => 'vacaciones']), false);

        $this->generar('2026-11-06');

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()->assertDontSee('Este mes cumplen aniversario');
    }

    public function test_sin_nadie_por_pagar_no_hay_aviso(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $this->persona('Carla', 'FICTICIO-3', '2026-01-15');

        $this->actingAs($this->actor)->get(route('planilla.index'))->assertOk()->assertDontSee('Este mes cumplen aniversario');
    }

    public function test_el_enlace_abre_el_formulario_con_vacaciones_elegida(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));

        $this->actingAs($this->actor)->get(route('planilla.create', ['clase' => 'vacaciones']))->assertOk()
            ->assertSee('<option value="vacaciones" selected', false)
            ->assertSee('value="2026-11-06"', false);
    }

    public function test_permisos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $this->persona('Ana', 'FICTICIO-1', '2020-11-10');

        // Solo ver: el aviso con la cantidad, sin enlace, y no puede generar.
        $ver = User::factory()->create(['activo' => true]);
        $ver->syncPermissions(['planilla.ver']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($ver)->get(route('planilla.index'))->assertOk()
            ->assertSee('Este mes cumplen aniversario:')
            ->assertDontSee('Generar la planilla de vacaciones');
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
