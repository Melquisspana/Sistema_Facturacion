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
use App\Services\Planilla\BajaEmpleado;
use App\Services\Planilla\GenerarPlanilla;
use App\Services\Planilla\IndemnizacionBaja;
use App\Services\Planilla\PrepararPlanilla;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Indemnización por despido (Código de Trabajo Art. 58) y prestación por renuncia
 * voluntaria, al dar de baja. Valores esperados escritos a mano, con la cuenta al lado.
 * El salario mínimo de la configuración es 408.80, así que el tope de despido es 1,635.20
 * al mes (54.5067 diarios) y el de renuncia, 817.60 (27.2533 diarios). Datos ficticios.
 */
class IndemnizacionBajaTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
    }

    private function persona(string $ingreso, string $sueldo = '408.80', bool $descuentos = false, string $dui = 'FICTICIO-1', string $nombres = 'Ana'): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombres, 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => $descuentos, 'alta_el' => $ingreso, 'sueldo_mensual' => $sueldo,
            'sueldo_desde' => '2015-01-01', 'confirmar_bajo_minimo' => true, 'cargo' => 'Vendedora',
        ]);
    }

    private function deBaja(string $ingreso, string $baja, string $motivo, string $sueldo = '408.80', bool $descuentos = false): PlanillaEmpleado
    {
        $e = $this->persona($ingreso, $sueldo, $descuentos);
        app(BajaEmpleado::class)->dar($this->actor, $e, $baja, $motivo);

        return $e->fresh();
    }

    private function calculo(PlanillaEmpleado $e, string $motivo): array
    {
        return app(IndemnizacionBaja::class)->calcular($e, $motivo);
    }

    private function generar(PlanillaEmpleado $e, array $datos = ['fecha_pago' => '2026-11-06']): Planilla
    {
        return app(IndemnizacionBaja::class)->generar($this->actor, $e, $datos);
    }

    // ── Despido sin causa ─────────────────────────────────────────────────

    public function test_despido_con_cinco_anos_y_tres_meses(): void
    {
        // Del 01/08/2021 al 31/10/2026, el día de baja incluido: 5 años y 92 días = 1,917
        // días a 365 por año = 5.2521 años. Diario 408.80 ÷ 30 = 13.6267, sin tope.
        // 30 × 13.6267 × 5.2521 = 408.80 × 1,917 ÷ 365 = 2,147.04 (exacto: 408.80 = 365 × 1.12).
        $c = $this->calculo($this->deBaja('2021-08-01', '2026-10-31', 'despido'), 'despido');

        $this->assertNull($c['fuera']);
        $this->assertNull($c['falta']);
        $this->assertSame(['anios' => 5, 'dias' => 92, 'texto' => '5 años y 92 días'], $c['tiempo']);
        $this->assertSame('5.2521', $c['anios_decimal']);
        $this->assertSame('13.6267', $c['diario']);
        $this->assertSame('13.6267', $c['diario_usado']);
        $this->assertFalse($c['con_tope']);
        $this->assertSame('54.5067', $c['tope_diario']);
        $this->assertSame('157.56', $c['dias_pagados']);
        $this->assertFalse($c['al_minimo']);
        $this->assertSame(214704, $c['centavos']);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function redondeos(): array
    {
        return [
            // 500.00 × 1,917 ÷ 365 = 2,626.0274 dólares = 262,602.74 centavos: sube a 2,626.03.
            'sube' => ['500.00', 262603],
            // 450.00 × 1,917 ÷ 365 = 236,342.47 centavos: queda en 2,363.42.
            'baja' => ['450.00', 236342],
        ];
    }

    #[DataProvider('redondeos')]
    public function test_despido_redondea_una_sola_vez_al_centavo(string $sueldo, int $esperado): void
    {
        $c = $this->calculo($this->deBaja('2021-08-01', '2026-10-31', 'despido', $sueldo), 'despido');

        $this->assertSame($esperado, $c['centavos']);
    }

    public function test_despido_con_sueldo_alto_usa_el_tope_de_cuatro_minimos(): void
    {
        // Del 01/01/2016 al 31/12/2025: 10 años justos. 2,000.00 pasa de 4 × 408.80 =
        // 1,635.20: el diario es 54.5067 y no 66.6667. 1,635.20 × 10 = 16,352.00 (sin
        // tope serían 20,000.00).
        $c = $this->calculo($this->deBaja('2016-01-01', '2025-12-31', 'despido', '2000.00', true), 'despido');

        $this->assertSame('10 años', $c['tiempo']['texto']);
        $this->assertTrue($c['con_tope']);
        $this->assertSame('66.6667', $c['diario']);
        $this->assertSame('54.5067', $c['diario_usado']);
        $this->assertSame(1635200, $c['centavos']);
    }

    public function test_despido_con_cuatro_meses_paga_el_minimo_de_quince_dias(): void
    {
        // Del 01/07/2026 al 31/10/2026: 123 días. 30 × 123 ÷ 365 = 10.11 días, menos de 15:
        // se pagan 15. 408.80 ÷ 30 × 15 = 204.40.
        $c = $this->calculo($this->deBaja('2026-07-01', '2026-10-31', 'despido'), 'despido');

        $this->assertSame('123 días', $c['tiempo']['texto']);
        $this->assertSame('10.11', $c['dias_pagados']);
        $this->assertTrue($c['al_minimo']);
        $this->assertSame(20440, $c['centavos']);
    }

    public function test_el_minimo_tambien_respeta_el_tope(): void
    {
        // 15 días de 54.5067 (tope) = 1,635.20 ÷ 2 = 817.60, no 15 días de 2,000.00 ÷ 30.
        $c = $this->calculo($this->deBaja('2026-07-01', '2026-10-31', 'despido', '2000.00', true), 'despido');

        $this->assertSame(81760, $c['centavos']);
    }

    public function test_los_numeros_salen_de_la_configuracion(): void
    {
        config(['planilla_prestaciones.indemnizacion.despido.dias_por_anio' => 15]);

        // La mitad de 2,147.04.
        $c = $this->calculo($this->deBaja('2021-08-01', '2026-10-31', 'despido'), 'despido');
        $this->assertSame(107352, $c['centavos']);
    }

    // ── Renuncia voluntaria ───────────────────────────────────────────────

    public function test_renuncia_con_un_ano_no_corresponde(): void
    {
        $e = $this->deBaja('2025-08-01', '2026-10-31', 'renuncia');
        $c = $this->calculo($e, 'renuncia');

        $this->assertSame('La prestación por renuncia voluntaria pide al menos 2 años de servicio continuo, y tiene 1 año y 92 días.', $c['fuera']);
        $this->assertSame(0, $c['centavos']);
        $this->assertThrows(fn () => $this->generar($e), ValidationException::class);
        $this->assertSame(0, Planilla::count());
    }

    public function test_renuncia_con_tres_anos(): void
    {
        // Del 01/08/2023 al 31/10/2026: 3 años y 92 días = 1,187 días. 15 días por año:
        // 408.80 ÷ 30 × 15 × 1,187 ÷ 365 = 664.72.
        $c = $this->calculo($this->deBaja('2023-08-01', '2026-10-31', 'renuncia'), 'renuncia');

        $this->assertNull($c['fuera']);
        $this->assertSame('3 años y 92 días', $c['tiempo']['texto']);
        $this->assertSame('48.78', $c['dias_pagados']);
        $this->assertSame('27.2533', $c['tope_diario']);
        $this->assertSame(66472, $c['centavos']);
    }

    public function test_renuncia_con_sueldo_alto_usa_el_tope_de_dos_minimos(): void
    {
        // 6 años justos. 1,200.00 pasa de 2 × 408.80 = 817.60: 817.60 ÷ 30 × 15 × 6 = 2,452.80
        // (sin tope serían 3,600.00).
        $c = $this->calculo($this->deBaja('2020-01-01', '2025-12-31', 'renuncia', '1200.00', true), 'renuncia');

        $this->assertTrue($c['con_tope']);
        $this->assertSame('27.2533', $c['diario_usado']);
        $this->assertSame(245280, $c['centavos']);
    }

    // ── Otro motivo y datos que faltan ────────────────────────────────────

    public function test_otro_motivo_no_calcula(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'otro');
        $c = $this->calculo($e, 'otro');

        $this->assertSame('Por el motivo de la baja no corresponde indemnización ni prestación por renuncia.', $c['fuera']);
        $this->assertSame(0, $c['centavos']);
        $this->assertThrows(fn () => $this->generar($e), ValidationException::class);

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('No se calcula.')->assertSee('no corresponde indemnización')
            ->assertDontSee('Sí, generar');
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Otro motivo (con causa, fin de contrato…) · no corresponde indemnización')
            ->assertDontSee('Calcular indemnización');
        $this->assertSame(0, Planilla::count());
    }

    public function test_quien_sigue_activo_no_calcula(): void
    {
        $e = $this->persona('2021-08-01');

        $this->assertSame('Todavía está activo: primero hay que darle de baja.', $this->calculo($e, 'despido')['fuera']);
        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))
            ->assertRedirect(route('personal.empleados.show', 'p'.$e->id));
        $this->assertThrows(fn () => $this->generar($e, ['motivo' => 'despido', 'fecha_pago' => '2026-11-06']), ValidationException::class, 'Todavía está activo');
        $this->assertSame(0, Planilla::count());
    }

    public function test_sin_fecha_de_ingreso_dice_que_falta(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        $e->update(['alta_el' => null]);

        $this->assertSame('la fecha de ingreso', $this->calculo($e->fresh(), 'despido')['falta']);
        $this->assertThrows(fn () => $this->generar($e->fresh()), ValidationException::class, 'Falta la fecha de ingreso para calcularlo.');
    }

    // ── La planilla ───────────────────────────────────────────────────────

    public function test_genera_una_planilla_de_esa_sola_persona_sin_descuentos(): void
    {
        // Va con descuentos de ley en la quincena, pero la indemnización no los lleva.
        $this->persona('2020-01-01', '600.00', true, 'FICTICIO-OTRA', 'Bea');
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido', '408.80', true);

        $planilla = $this->generar($e);

        $this->assertSame('indemnizacion', $planilla->clase);
        $this->assertSame('confirmada', $planilla->estado);
        $this->assertSame('IND-'.$e->id.'-20261031', $planilla->periodo);
        $this->assertSame('2026-10-31', $planilla->desde->toDateString());
        $this->assertSame('2026-11-06', $planilla->fecha_pago->toDateString());

        $linea = PlanillaDetalle::where('planilla_id', $planilla->id)->sole();
        $this->assertSame($e->id, $linea->planilla_empleado_id);
        $this->assertSame('2021-08-01', $linea->periodo_desde->toDateString());
        $this->assertSame('2026-10-31', $linea->periodo_hasta->toDateString());
        $this->assertSame(1, $linea->conceptos->count());
        $this->assertSame('Indemnización por despido', $linea->conceptos->first()->concepto);
        $this->assertSame('2147.04', (string) $linea->total_ingresos);
        $this->assertSame('0.00', (string) $linea->descuentos);
        $this->assertSame('2147.04', (string) $linea->a_pagar);
        $this->assertNotNull($linea->gasto_id);
        $this->assertStringContainsString('5 años y 92 días (5.2521 años)', $linea->observaciones);
        $this->assertStringContainsString('Salario diario 13.6267 (408.80 ÷ 30)', $linea->observaciones);

        // Bitácora sin importes.
        $bitacora = Activity::where('description', 'generó la indemnización por despido')->sole();
        $this->assertSame('despido', $bitacora->getExtraProperty('motivo'));
        $this->assertStringNotContainsString('2147', json_encode($bitacora->properties));
    }

    public function test_con_el_interruptor_lleva_descuentos_de_ley(): void
    {
        config(['planilla_prestaciones.indemnizacion.con_descuentos_ley' => true]);
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido', '408.80', true);

        $linea = PlanillaDetalle::where('planilla_id', $this->generar($e)->id)->sole();

        $this->assertEqualsCanonicalizing(['isss', 'afp', 'renta'], $linea->conceptos->pluck('ley')->filter()->values()->all());
    }

    public function test_renuncia_genera_su_propia_prestacion(): void
    {
        $e = $this->deBaja('2023-08-01', '2026-10-31', 'renuncia');
        $planilla = $this->generar($e);

        $this->assertSame('REN-'.$e->id.'-20261031', $planilla->periodo);
        $linea = PlanillaDetalle::where('planilla_id', $planilla->id)->sole();
        $this->assertSame('Prestación por renuncia voluntaria', $linea->conceptos->first()->concepto);
        $this->assertSame('664.72', (string) $linea->a_pagar);
    }

    public function test_no_se_genera_dos_veces_para_la_misma_baja(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        $planilla = $this->generar($e);

        $this->assertThrows(fn () => $this->generar($e), ValidationException::class, 'Ya se generó la indemnización por despido de esta baja');

        // La pantalla lleva a la que ya existe, y la ficha la enlaza en vez de ofrecer calcularla.
        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertRedirect(route('planilla.show', $planilla));
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Ver la indemnización por despido')->assertDontSee('Calcular indemnización');

        // Anulada, el período sigue ocupado: hay que borrarla para hacerla de nuevo.
        app(AnularPlanilla::class)->anular($this->actor, $planilla, 'Prueba de anulación');
        $this->assertThrows(fn () => $this->generar($e), ValidationException::class, 'quedó anulada');
        $this->assertSame(1, Planilla::count());
    }

    public function test_no_se_abre_desde_generar_planilla(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.create', ['clase' => 'indemnizacion']))->assertOk()
            ->assertDontSee('value="indemnizacion"', false);

        $this->assertThrows(fn () => app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'mensual', 'fecha' => '2026-11-06', 'clase' => 'indemnizacion', 'moneda' => 'USD', 'fecha_pago' => '2026-11-06',
        ]), ValidationException::class);
        $this->assertSame(0, Planilla::count());
    }

    public function test_no_cruza_con_la_quincena_de_la_baja(): void
    {
        // La última quincena la cobra activa, y después se va.
        $e = $this->persona('2021-08-01');
        $quincena = app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-10-16', 'clase' => 'regular', 'moneda' => 'USD']);
        app(BajaEmpleado::class)->dar($this->actor, $e, '2026-10-31', 'despido');
        $planilla = $this->generar($e->fresh());

        $this->assertSame(1, PlanillaDetalle::where('planilla_id', $quincena->id)->count());

        $this->assertSame([], app(PrepararPlanilla::class)->avisosParaConfirmar($quincena->fresh(), true));
        $this->assertSame([], app(PrepararPlanilla::class)->avisosParaConfirmar($planilla->fresh(), true));
    }

    // ── La baja y la ficha ────────────────────────────────────────────────

    public function test_la_baja_pide_el_motivo_y_lo_deja_en_la_bitacora(): void
    {
        $e = $this->persona('2021-08-01');

        $this->actingAs($this->actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-10-31'])->assertSessionHasErrors('motivo');
        $this->actingAs($this->actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-10-31', 'motivo' => 'jubilado'])->assertSessionHasErrors('motivo');
        $this->assertTrue($e->fresh()->activo);

        $this->actingAs($this->actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-10-31', 'motivo' => 'despido'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('planilla.aviso', fn ($aviso) => str_contains($aviso, 'La indemnización por despido se calcula desde su ficha.'));

        $baja = Activity::where('subject_id', $e->id)->where('description', 'dio de baja al empleado')->sole();
        $this->assertSame(['fecha' => '2026-10-31', 'motivo' => 'despido'], $baja->properties->only(['fecha', 'motivo'])->all());
        $this->assertSame('despido', app(IndemnizacionBaja::class)->motivoDeBaja($e->fresh()));

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Motivo de la baja:')->assertSee('Despido sin causa justificada')
            ->assertSee('href="'.route('planilla.empleados.indemnizacion', $e).'"', false)
            ->assertSee('Calcular indemnización por despido');
    }

    public function test_el_formulario_de_baja_ofrece_los_tres_motivos(): void
    {
        $e = $this->persona('2021-08-01');

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('<select id="motivo_baja" name="motivo" required', false)
            ->assertSeeInOrder(['Despido sin causa justificada', 'Renuncia voluntaria', 'Otro motivo (con causa, fin de contrato…)']);
    }

    public function test_reactivar_quita_el_motivo_de_la_ficha(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        app(BajaEmpleado::class)->reactivar($this->actor, $e);

        $this->assertNull(app(IndemnizacionBaja::class)->motivoDeBaja($e->fresh()));
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertDontSee('Motivo de la baja:')->assertDontSee('Calcular indemnización');
    }

    public function test_una_baja_sin_motivo_lo_elige_en_la_pantalla(): void
    {
        // Dada de baja antes de que se preguntara el motivo: sin nada en la bitácora.
        $e = $this->persona('2021-08-01');
        $e->update(['activo' => false, 'baja_el' => '2026-10-31']);

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Sin dato: la baja es de antes de que se preguntara')
            ->assertSee('Calcular indemnización o prestación por renuncia');

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('Esta baja se registró sin motivo.')->assertDontSee('Sí, generar');

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', ['empleado' => $e, 'motivo' => 'despido']))->assertOk()
            ->assertSee('2,147.04')->assertSee('<input type="hidden" name="motivo" value="despido">', false);

        $this->assertThrows(fn () => $this->generar($e), ValidationException::class, 'Elegí el motivo de la baja.');

        $this->actingAs($this->actor)->post(route('planilla.empleados.indemnizacion.store', $e), ['motivo' => 'despido', 'fecha_pago' => '2026-11-06'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('IND-'.$e->id.'-20261031', Planilla::sole()->periodo);
    }

    public function test_el_motivo_de_la_bitacora_manda_sobre_la_pantalla(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $this->actingAs($this->actor)->post(route('planilla.empleados.indemnizacion.store', $e), ['motivo' => 'renuncia', 'fecha_pago' => '2026-11-06'])
            ->assertSessionHasNoErrors();
        $this->assertSame('IND-'.$e->id.'-20261031', Planilla::sole()->periodo);
    }

    // ── Pantalla del cálculo y recibo ─────────────────────────────────────

    public function test_la_pantalla_muestra_el_calculo_y_no_crea_nada(): void
    {
        $e = $this->deBaja('2016-01-01', '2025-12-31', 'despido', '2000.00', true);

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('Indemnización por despido · Ana Ficticia')
            ->assertSeeInOrder(['Tiempo de servicio', '10 años', '(10.0000 años)', 'del 01/01/2016 al 31/12/2025',
                'Sueldo mensual a la fecha de baja', '2,000.00',
                'Salario diario usado', '54.5067', 'Se aplicó el tope de 4 salarios mínimos diarios. El suyo es 66.6667',
                'Días que se pagan', '300.00', 'Sin ISSS, AFP ni renta', '16,352.00'])
            ->assertSee('Sí, generar la indemnización por despido');

        $this->assertSame(0, Planilla::count());
    }

    public function test_generar_por_la_pantalla_lleva_al_recibo(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $this->actingAs($this->actor)->post(route('planilla.empleados.indemnizacion.store', $e), [])->assertSessionHasErrors('fecha_pago');

        $planilla = null;
        $this->actingAs($this->actor)->post(route('planilla.empleados.indemnizacion.store', $e), ['fecha_pago' => '2026-11-06'])
            ->assertRedirect(route('planilla.impresos', ['planilla' => $planilla = Planilla::sole(), 'formato' => 'recibo']));
        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()->assertSee('Indemnización o renuncia');
    }

    public function test_el_recibo_dice_que_es_de_indemnizacion_con_el_puesto_y_las_dos_firmas(): void
    {
        config(['planilla.pagos_enabled' => false]);
        Empresa::create(['razon_social' => 'Patrona Ejemplo', 'nombre_comercial' => 'Negocio Ejemplo', 'nit' => '00000000000000', 'ambiente' => '00', 'activo' => true]);
        $planilla = $this->generar($this->deBaja('2021-08-01', '2026-10-31', 'despido'));

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de indemnización')
            ->assertSeeInOrder(['Ana Ficticia', 'Puesto: Vendedora'])
            ->assertSeeInOrder(['Recibí de Patrona Ejemplo', 'la cantidad de US$', '2,147.04', 'en concepto de indemnización por despido, por',
                '5 años y 92 días de servicio, del 01/08/2021 al 31/10/2026.'])
            ->assertSeeInOrder(['Detalle del cálculo', 'Despido sin causa justificada.', '30 días por año: 157.56 días.'])
            ->assertSeeInOrder(['Tiempo de servicio', 'del 01/08/2021 al 31/10/2026'])
            ->assertSeeInOrder(['Indemnización por despido', '2147.04'])
            // Firman la persona y la empleadora; el sello va sobre la firma, sin recuadro.
            ->assertSeeInOrder(['Recibí conforme', 'Firma y sello de la empleadora', 'Patrona Ejemplo'])
            ->assertDontSee('Entregó el pago')
            ->assertDontSee('Período de goce')
            ->assertSee('No incluye aguinaldo ni vacaciones proporcionales.');
    }

    public function test_el_recibo_de_renuncia_tiene_su_titulo(): void
    {
        config(['planilla.pagos_enabled' => false]);
        $planilla = $this->generar($this->deBaja('2023-08-01', '2026-10-31', 'renuncia'));

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de prestación por renuncia voluntaria')
            ->assertSee('en concepto de prestación económica por renuncia voluntaria, por')
            ->assertSee('3 años y 92 días de servicio')
            ->assertDontSee('Recibo de indemnización');
    }

    public function test_el_recibo_de_una_quincena_no_cambia(): void
    {
        $this->persona('2021-08-01');
        $quincena = app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-01', 'clase' => 'regular', 'moneda' => 'USD']);

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $quincena, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de pago')->assertSee('Período pagado')->assertSee('Entregó el pago')
            ->assertSee('No incluye aporte patronal, indemnización ni horas extra.')
            ->assertDontSee('Firma y sello de la empleadora')->assertDontSee('Tiempo de servicio')
            ->assertDontSee('Detalle del cálculo')->assertDontSee('Recibo de indemnización');
    }

    // ── Permisos ──────────────────────────────────────────────────────────

    public function test_permisos(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        $usuario = function (array $permisos): User {
            $u = User::factory()->create(['activo' => true]);
            $u->syncPermissions($permisos);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $u;
        };

        // Gestionar sin ver salarios, ver salarios sin gestionar, y solo ver: ninguno.
        foreach ([['planilla.ver', 'planilla.gestionar'], ['planilla.ver', 'planilla.salarios'], ['planilla.ver']] as $permisos) {
            $u = $usuario($permisos);
            $this->actingAs($u)->get(route('planilla.empleados.indemnizacion', $e))->assertForbidden();
            $this->actingAs($u)->post(route('planilla.empleados.indemnizacion.store', $e), ['fecha_pago' => '2026-11-06'])->assertForbidden();
            $this->assertThrows(fn () => app(IndemnizacionBaja::class)->generar($u, $e, ['fecha_pago' => '2026-11-06']));
        }

        // Sin ver salarios, la ficha no ofrece calcularla.
        $this->actingAs($usuario(['planilla.ver', 'planilla.gestionar']))->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Motivo de la baja:')->assertDontSee('Calcular indemnización');

        // Un usuario desactivado tampoco.
        $inactivo = $usuario(PermisoSistema::todos());
        $inactivo->update(['activo' => false]);
        $this->assertThrows(fn () => app(IndemnizacionBaja::class)->generar($inactivo, $e, ['fecha_pago' => '2026-11-06']));

        $this->assertSame(0, Planilla::count());
    }
}
