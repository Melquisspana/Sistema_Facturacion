<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Empresa;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaSueldo;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\BajaEmpleado;
use App\Services\Planilla\BorrarPlanilla;
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
 * Indemnización por despido sin causa (Código de Trabajo Art. 58) y prestación por renuncia
 * voluntaria, como PAGO DE UNA PERSONA desde su baja, igual que las vacaciones. Valores
 * esperados escritos a mano, con la cuenta al lado. El salario mínimo de la configuración es
 * 408.80, así que el tope de despido es 4 × 408.80 = 1,635.20 al mes (54.5067 diarios) y el
 * de renuncia 2 × 408.80 = 817.60 (27.2533 diarios). Datos ficticios.
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

    private function calculo(PlanillaEmpleado $e, ?string $motivo = null): array
    {
        return app(IndemnizacionBaja::class)->calcular($e, $motivo);
    }

    /** Lo que manda el botón «Revisar el pago» de la pantalla del cálculo. */
    private function datos(PlanillaEmpleado $e, string $clase = 'indemnizacion', string $pago = '2026-11-06'): array
    {
        return ['tipo_periodo' => 'mensual', 'clase' => $clase, 'fecha' => (string) ($e->baja_el ?? '2026-11-06'), 'moneda' => 'USD', 'empleado' => $e->id, 'fecha_pago' => $pago];
    }

    private function generar(PlanillaEmpleado $e, string $clase = 'indemnizacion', string $pago = '2026-11-06', ?User $quien = null): Planilla
    {
        return app(GenerarPlanilla::class)->generar($quien ?? $this->actor, $this->datos($e, $clase, $pago));
    }

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    // ── Despido sin causa ─────────────────────────────────────────────────

    public function test_despido_con_cinco_anos_y_tres_meses(): void
    {
        // Del 01/08/2021 al 31/10/2026, el día de baja incluido: 5 años y 92 días = 1,917
        // días a 365 por año = 5.2521 años. Diario 408.80 ÷ 30 = 13.6267, sin tope.
        // 30 × 13.6267 × 5.2521 = 408.80 × 1,917 ÷ 365 = 2,147.04 (exacto: 408.80 = 365 × 1.12).
        $c = $this->calculo($this->deBaja('2021-08-01', '2026-10-31', 'despido'));

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
        $this->assertSame('Indemnización por despido', $c['concepto']);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function redondeos(): array
    {
        return [
            // 500.00 × 1,917 ÷ 365 = 2,626.0274: sube a 2,626.03.
            'sube' => ['500.00', 262603],
            // 450.00 × 1,917 ÷ 365 = 2,363.4247: queda en 2,363.42.
            'baja' => ['450.00', 236342],
        ];
    }

    #[DataProvider('redondeos')]
    public function test_despido_redondea_una_sola_vez_al_centavo(string $sueldo, int $esperado): void
    {
        $this->assertSame($esperado, $this->calculo($this->deBaja('2021-08-01', '2026-10-31', 'despido', $sueldo))['centavos']);
    }

    public function test_despido_con_sueldo_alto_usa_el_tope_de_cuatro_minimos(): void
    {
        // Del 01/01/2016 al 31/12/2025: 10 años justos. 2,000.00 pasa de 4 × 408.80 =
        // 1,635.20: el diario es 54.5067 y no 66.6667. 1,635.20 × 10 = 16,352.00 (sin tope
        // serían 20,000.00).
        $c = $this->calculo($this->deBaja('2016-01-01', '2025-12-31', 'despido', '2000.00', true));

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
        $c = $this->calculo($this->deBaja('2026-07-01', '2026-10-31', 'despido'));

        $this->assertSame('123 días', $c['tiempo']['texto']);
        $this->assertSame('10.11', $c['dias_pagados']);
        $this->assertTrue($c['al_minimo']);
        $this->assertSame(20440, $c['centavos']);
    }

    public function test_el_minimo_de_quince_dias_tambien_respeta_el_tope(): void
    {
        // 15 días de 54.5067 (tope) = 1,635.20 ÷ 2 = 817.60, no 15 días de 2,000.00 ÷ 30.
        $this->assertSame(81760, $this->calculo($this->deBaja('2026-07-01', '2026-10-31', 'despido', '2000.00', true))['centavos']);
    }

    public function test_los_numeros_salen_de_la_configuracion(): void
    {
        config(['planilla_prestaciones.indemnizacion.despido.dias_por_anio' => 15]);

        // La mitad de 2,147.04.
        $this->assertSame(107352, $this->calculo($this->deBaja('2021-08-01', '2026-10-31', 'despido'))['centavos']);
    }

    // ── Renuncia voluntaria ───────────────────────────────────────────────

    public function test_renuncia_con_un_ano_no_corresponde(): void
    {
        $e = $this->deBaja('2025-08-01', '2026-10-31', 'renuncia');
        $c = $this->calculo($e);

        $this->assertSame('la prestación por renuncia voluntaria pide al menos 2 años de servicio continuo, y tiene 1 año y 92 días', $c['fuera']);
        $this->assertSame(0, $c['centavos']);
        $this->assertThrows(fn () => $this->generar($e, 'renuncia'), ValidationException::class, 'pide al menos 2 años');
        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('No se calcula.')->assertSee('pide al menos 2 años')->assertDontSee('Revisar el pago');
        $this->assertSame(0, Planilla::count());
    }

    public function test_renuncia_con_tres_anos(): void
    {
        // Del 01/08/2023 al 31/10/2026: 3 años y 92 días = 1,187 días. 15 días por año:
        // 408.80 ÷ 30 × 15 × 1,187 ÷ 365 = 204.40 × 1,187 ÷ 365 = 664.72.
        $c = $this->calculo($this->deBaja('2023-08-01', '2026-10-31', 'renuncia'));

        $this->assertNull($c['fuera']);
        $this->assertSame('3 años y 92 días', $c['tiempo']['texto']);
        $this->assertSame('48.78', $c['dias_pagados']);
        $this->assertSame('27.2533', $c['tope_diario']);
        $this->assertFalse($c['al_minimo']);
        $this->assertSame(66472, $c['centavos']);
        $this->assertSame('Prestación por renuncia voluntaria', $c['concepto']);
    }

    public function test_renuncia_con_sueldo_alto_usa_el_tope_de_dos_minimos(): void
    {
        // 6 años justos. 1,200.00 pasa de 2 × 408.80 = 817.60: 817.60 ÷ 30 × 15 × 6 = 2,452.80
        // (sin tope serían 3,600.00).
        $c = $this->calculo($this->deBaja('2020-01-01', '2025-12-31', 'renuncia', '1200.00', true));

        $this->assertTrue($c['con_tope']);
        $this->assertSame('40.0000', $c['diario']);
        $this->assertSame('27.2533', $c['diario_usado']);
        $this->assertSame(245280, $c['centavos']);
    }

    // ── Otro motivo y datos que faltan ────────────────────────────────────

    public function test_otro_motivo_no_calcula(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'otro');

        $this->assertSame('por el motivo de la baja no corresponde indemnización ni prestación por renuncia', $this->calculo($e)['fuera']);
        $this->assertSame(0, $this->calculo($e)['centavos']);

        // Ni con una clase elegida a mano: la bitácora de la baja manda.
        foreach (['indemnizacion', 'renuncia'] as $clase) {
            $this->assertThrows(fn () => $this->generar($e, $clase), ValidationException::class, 'la baja fue por «Otro»');
        }

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('No se calcula.')->assertSee('no corresponde indemnización')->assertDontSee('Revisar el pago');
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Otro · no corresponde indemnización')
            ->assertDontSee('Calcular indemnización');
        $this->assertSame(0, Planilla::count());
    }

    public function test_quien_sigue_activo_no_calcula(): void
    {
        $e = $this->persona('2021-08-01');

        $this->assertSame('todavía está activa: primero hay que darle de baja', $this->calculo($e, 'despido')['fuera']);
        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))
            ->assertRedirect(route('personal.empleados.show', 'p'.$e->id));
        $this->assertThrows(fn () => $this->generar($e), ValidationException::class, 'se paga desde la baja');
        $this->assertSame(0, Planilla::count());
    }

    public function test_sin_fecha_de_ingreso_dice_que_falta(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        $e->update(['alta_el' => null]);

        $this->assertSame('la fecha de ingreso', $this->calculo($e->fresh())['falta']);
        $this->assertThrows(fn () => $this->generar($e->fresh()), ValidationException::class, 'falta la fecha de ingreso para calcularlo');
        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('Falta la fecha de ingreso para calcularlo.')->assertDontSee('Revisar el pago');
    }

    // ── El pago de una persona ────────────────────────────────────────────

    public function test_genera_el_pago_de_esa_sola_persona_sin_descuentos(): void
    {
        // Bea sigue activa y va con descuentos: no entra. Ana va con descuentos en la
        // quincena, pero la indemnización no los lleva.
        $this->persona('2020-01-01', '600.00', true, 'FICTICIO-OTRA', 'Bea');
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido', '408.80', true);

        $planilla = $this->generar($e);

        $this->assertSame('indemnizacion', $planilla->clase);
        $this->assertSame($e->id, (int) $planilla->individual_de);
        $this->assertSame('confirmada', $planilla->estado);
        $this->assertSame('2026-11-06', $planilla->fecha_pago->toDateString());
        // La clave técnica es el mes de la baja; las fechas, el día de la baja.
        $this->assertSame('2026-10', $planilla->periodo);
        $this->assertSame('2026-10-31', $planilla->desde->toDateString());
        $this->assertSame('2026-10-31', $planilla->hasta->toDateString());

        $linea = PlanillaDetalle::where('planilla_id', $planilla->id)->sole();
        $this->assertSame($e->id, $linea->planilla_empleado_id);
        $this->assertSame('2021-08-01', $linea->periodo_desde->toDateString());
        $this->assertSame('2026-10-31', $linea->periodo_hasta->toDateString());
        $this->assertSame(['Indemnización por despido'], $linea->conceptos->pluck('concepto')->all());
        $this->assertSame([], $linea->conceptos->pluck('ley')->filter()->values()->all());
        $this->assertSame('0.00', (string) $linea->salario);
        $this->assertSame('2147.04', (string) $linea->total_ingresos);
        $this->assertSame('0.00', (string) $linea->descuentos);
        $this->assertSame('2147.04', (string) $linea->a_pagar);
        $this->assertStringContainsString('5 años y 92 días (5.2521 años)', $linea->observaciones);
        $this->assertStringContainsString('Salario diario 13.6267 (408.80 ÷ 30)', $linea->observaciones);

        // Lo que se le debe, sin el mes técnico.
        $gasto = Gasto::findOrFail($linea->gasto_id);
        $this->assertSame('Indemnización · Ana Ficticia', $gasto->concepto);
        $this->assertNull($gasto->periodo_desde);
        $this->assertNull($gasto->periodo_hasta);
    }

    public function test_con_el_interruptor_lleva_descuentos_de_ley(): void
    {
        config(['planilla_prestaciones.indemnizacion.con_descuentos_ley' => true]);
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido', '408.80', true);

        $linea = PlanillaDetalle::where('planilla_id', $this->generar($e)->id)->sole();

        $this->assertEqualsCanonicalizing(['isss', 'afp', 'renta'], $linea->conceptos->pluck('ley')->filter()->values()->all());
    }

    public function test_renuncia_genera_su_propio_pago(): void
    {
        $e = $this->deBaja('2023-08-01', '2026-10-31', 'renuncia');
        $planilla = $this->generar($e, 'renuncia');

        $this->assertSame('renuncia', $planilla->clase);
        $linea = PlanillaDetalle::where('planilla_id', $planilla->id)->sole();
        $this->assertSame('Prestación por renuncia voluntaria', $linea->conceptos->first()->concepto);
        $this->assertSame('664.72', (string) $linea->a_pagar);
        $this->assertSame('Prestación por renuncia de Ana Ficticia', $planilla->fresh()->nombreDelPago());
    }

    public function test_la_clase_tiene_que_ser_la_del_motivo(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $this->assertThrows(fn () => $this->generar($e, 'renuncia'), ValidationException::class, 'la baja fue por «Despido sin causa»');
        $this->assertSame(0, Planilla::count());
    }

    public function test_no_se_genera_dos_veces_para_la_misma_baja(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        $planilla = $this->generar($e);

        // Ni otra vez, ni con otra fecha de pago, ni como la otra clase.
        $this->assertThrows(fn () => $this->generar($e), ValidationException::class, 'El pago de esta baja ya está hecho');
        $this->assertThrows(fn () => $this->generar($e, 'indemnizacion', '2026-11-20'), ValidationException::class, 'El pago de esta baja ya está hecho');
        $this->assertThrows(fn () => $this->generar($e, 'renuncia'), ValidationException::class, 'El pago de esta baja ya está hecho');
        $this->assertSame(1, Planilla::count());

        // La revisión y el cálculo llevan al que ya existe; la ficha lo enlaza.
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->datos($e)))->assertRedirect(route('planilla.show', $planilla));
        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertRedirect(route('planilla.show', $planilla));
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Ver pago de indemnización de Ana Ficticia')->assertDontSee('Calcular indemnización');
    }

    public function test_anulado_se_puede_generar_de_nuevo(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        $primero = $this->generar($e);

        app(AnularPlanilla::class)->anular($this->actor, $primero, 'Fecha de pago equivocada');

        // Anulado deja libre su lugar en la clave única.
        $this->assertSame('2026-10-A'.$primero->id, $primero->fresh()->periodo);
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Calcular indemnización');

        $segundo = $this->generar($e, 'indemnizacion', '2026-11-20');

        $this->assertNotSame($primero->id, $segundo->id);
        $this->assertSame('confirmada', $segundo->estado);
        $this->assertSame('2026-10', $segundo->periodo);
        $this->assertSame('2147.04', (string) PlanillaDetalle::where('planilla_id', $segundo->id)->sole()->a_pagar);
        $this->assertThrows(fn () => $this->generar($e), ValidationException::class, 'ya está hecho');
    }

    public function test_siempre_una_sola_persona(): void
    {
        $bea = $this->persona('2020-01-01', '600.00', false, 'FICTICIO-OTRA', 'Bea');
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $borrador = app(PrepararPlanilla::class)->abrir($this->actor, $this->datos($e));
        $this->assertSame([$e->id], $borrador->detalles()->pluck('planilla_empleado_id')->all());

        // Ni agregar a otra a mano, ni cambiar a la persona.
        foreach ([[$e->id, $bea->id], [$bea->id]] as $ids) {
            $lineas = array_map(fn (int $id) => ['planilla_empleado_id' => $id, 'salario' => '0.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Indemnización por despido', 'importe' => '2147.04'],
            ]], $ids);
            $this->assertThrows(fn () => app(PrepararPlanilla::class)->guardarLineas($this->actor, $borrador, $lineas),
                ValidationException::class, 'Un pago de indemnización es de una sola persona');
        }

        // Sin descuentos de ley, lo de Bea queda fuera de este pago.
        $this->actingAs($this->actor)->get(route('planilla.preparar', $borrador))->assertOk()
            ->assertDontSee('Bea Ficticia')->assertSee('Un pago de indemnización es de una sola persona', false);
    }

    public function test_sin_persona_no_hay_pago_de_indemnizacion(): void
    {
        $this->assertThrows(fn () => app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'mensual', 'fecha' => '2026-11-06', 'clase' => 'indemnizacion', 'moneda' => 'USD', 'fecha_pago' => '2026-11-06',
        ]), ValidationException::class, 'se paga desde la baja');
        $this->assertSame(0, Planilla::count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function fechasDePago(): array
    {
        return [
            'antes de la baja' => ['2026-10-30', 'no puede ser anterior a la baja (31/10/2026)'],
            'de un mes que no llegó' => ['2026-12-01', 'de un mes que todavía no llegó'],
        ];
    }

    #[DataProvider('fechasDePago')]
    public function test_valida_la_fecha_de_pago(string $pago, string $mensaje): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $this->assertThrows(fn () => $this->generar($e, 'indemnizacion', $pago), ValidationException::class, $mensaje);
        $this->assertSame(0, Planilla::count());
    }

    // ── La baja y su motivo ───────────────────────────────────────────────

    public function test_la_baja_pide_el_motivo_y_lo_deja_en_la_bitacora(): void
    {
        $e = $this->persona('2021-08-01');

        $this->actingAs($this->actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-10-31'])->assertSessionHasErrors('motivo_baja');
        $this->actingAs($this->actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-10-31', 'motivo_baja' => 'jubilado'])->assertSessionHasErrors('motivo_baja');
        $this->assertTrue($e->fresh()->activo);

        $this->actingAs($this->actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-10-31', 'motivo_baja' => 'despido'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('planilla.aviso', fn ($aviso) => str_contains($aviso, 'La indemnización se calcula desde su ficha.'));

        $baja = Activity::where('subject_id', $e->id)->where('description', 'dio de baja al empleado')->sole();
        $this->assertSame(['fecha' => '2026-10-31', 'motivo' => 'despido'], $baja->properties->only(['fecha', 'motivo'])->all());
        $this->assertSame('despido', app(IndemnizacionBaja::class)->motivoDeBaja($e->fresh()));

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Motivo de la baja:')->assertSee('Despido sin causa')
            ->assertSee('href="'.route('planilla.empleados.indemnizacion', $e).'"', false)
            ->assertSee('Calcular indemnización');
    }

    public function test_la_ficha_de_renuncia_ofrece_la_prestacion(): void
    {
        $e = $this->deBaja('2023-08-01', '2026-10-31', 'renuncia');

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Renuncia voluntaria')->assertSee('Calcular prestación por renuncia');
    }

    public function test_el_formulario_de_baja_ofrece_los_tres_motivos(): void
    {
        $e = $this->persona('2021-08-01');

        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('<select id="motivo_baja" name="motivo_baja" required', false)
            ->assertSeeInOrder(['Elegí el motivo', 'Despido sin causa', 'Renuncia voluntaria', 'Otro']);
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
            ->assertSee('Esta baja se registró sin motivo.')->assertDontSee('Revisar el pago');

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', ['empleado' => $e, 'motivo' => 'despido']))->assertOk()
            ->assertSee('2,147.04')->assertSee('<input type="hidden" name="clase" value="indemnizacion">', false);

        $this->assertSame('2147.04', (string) PlanillaDetalle::where('planilla_id', $this->generar($e->fresh())->id)->sole()->a_pagar);
    }

    // ── Las pantallas ─────────────────────────────────────────────────────

    public function test_la_pantalla_del_calculo_muestra_todo_y_no_crea_nada(): void
    {
        $e = $this->deBaja('2016-01-01', '2025-12-31', 'despido', '2000.00', true);

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('Calcular indemnización de Ana Ficticia')
            ->assertSeeInOrder(['Años trabajados', '10 años', '(10.0000 años)', 'del 01/01/2016 al 31/12/2025',
                'Sueldo mensual a la fecha de baja', '2,000.00',
                'Salario diario usado', '54.5067', 'Se aplicó el tope de 4 salarios mínimos diarios. El suyo es 66.6667',
                'Días que se pagan', '300.00', 'Sin ISSS, AFP ni renta', '16,352.00'])
            ->assertSee('Revisar el pago')
            ->assertSee('action="'.route('planilla.revisar').'"', false)
            ->assertDontSee('Mensual')->assertDontSee('quincena')->assertDontSee('2025-12 ');

        $this->assertSame(0, Planilla::count());
    }

    public function test_la_revision_del_pago_no_muestra_periodo_ni_quincena(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->datos($e)))->assertOk()
            ->assertSee('Pago de indemnización de Ana Ficticia')
            ->assertSee('Tiempo de servicio: del 01/08/2021 al 31/10/2026')
            ->assertSee('Pago el 06/11/2026')
            ->assertSeeInOrder(['Indemnización por despido', '2,147.04', 'A pagar', '2147.04'])
            ->assertSee('Sí, generar el pago')
            ->assertSee('href="'.route('planilla.empleados.indemnizacion', $e).'"', false)
            ->assertDontSee('Mensual')->assertDontSee('quincena')->assertDontSee('2026-10 ')->assertDontSee('>2026-10<', false)->assertDontSee('01/10/2026');
        $this->assertSame(0, Planilla::count());
    }

    public function test_generar_lleva_al_recibo_y_ninguna_pantalla_muestra_periodo(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->datos($e));
        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))
            ->assertSessionHas('planilla.aviso', 'Pago de indemnización de Ana Ficticia generado. Imprimí el recibo y hacelo firmar.');

        $sinPeriodo = fn ($r) => $r->assertOk()->assertDontSee('Mensual')->assertDontSee('quincena')
            ->assertDontSee('2026-10 ·')->assertDontSee('>2026-10<', false)->assertDontSee('01/10/2026');

        $sinPeriodo($this->actingAs($this->actor)->get(route('planilla.show', $planilla)))
            ->assertSee('Pago de indemnización de Ana Ficticia')
            ->assertSee('Tiempo de servicio: del 01/08/2021 al 31/10/2026')
            ->assertSee('Pago el 06/11/2026')
            ->assertSee('Anular el pago');
        $sinPeriodo($this->actingAs($this->actor)->get(route('planilla.index')))
            ->assertSeeInOrder(['Pago de indemnización de Ana Ficticia', 'Tiempo de servicio: del 01/08/2021 al 31/10/2026 · Pago el 06/11/2026', 'Indemnización']);
        $sinPeriodo($this->actingAs($this->actor)->get(route('planilla.impresos', $planilla)))
            ->assertSee('Impresos · Pago de indemnización de Ana Ficticia')
            ->assertSee('Indemnización para firma')->assertSee('Tiempo de servicio');
        $sinPeriodo($this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo'])));
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSeeInOrder(['Últimos pagos', 'Pago del 06/11/2026', 'Indemnización'])
            ->assertDontSee('>2026-10<', false);
    }

    public function test_el_recibo_dice_que_es_de_indemnizacion_con_el_puesto_y_las_dos_firmas(): void
    {
        config(['planilla.pagos_enabled' => false]);
        Empresa::create(['razon_social' => 'Patrona Ejemplo', 'nombre_comercial' => 'Negocio Ejemplo', 'nit' => '00000000000000', 'ambiente' => '00', 'activo' => true]);
        $planilla = $this->generar($this->deBaja('2021-08-01', '2026-10-31', 'despido'));

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de indemnización')
            ->assertSee('IND-'.str_pad((string) $planilla->id, 5, '0', STR_PAD_LEFT))
            ->assertSeeInOrder(['Ana Ficticia', 'Puesto: Vendedora'])
            ->assertSeeInOrder(['Recibí de Patrona Ejemplo la cantidad de US$', '2,147.04', 'en concepto de indemnización por despido, por',
                '5 años y 92 días de servicio, del 01/08/2021 al 31/10/2026.'])
            ->assertSeeInOrder(['Detalle del cálculo', 'Despido sin causa.', '30 días por año: 157.56 días.'])
            ->assertSeeInOrder(['Tiempo de servicio', 'del 01/08/2021 al 31/10/2026'])
            ->assertSeeInOrder(['Indemnización por despido', '2147.04'])
            // Firman la persona y la empleadora; el sello va sobre la firma, sin recuadro.
            ->assertSeeInOrder(['Recibí conforme', 'Firma y sello de la empleadora', 'Patrona Ejemplo'])
            ->assertDontSee('Entregó el pago')
            ->assertDontSee('Período de goce')
            ->assertDontSee('Período pagado')
            ->assertDontSee('Descuentos')
            ->assertSee('No incluye aguinaldo ni vacaciones proporcionales.');
    }

    public function test_el_recibo_de_renuncia_tiene_su_titulo(): void
    {
        config(['planilla.pagos_enabled' => false]);
        $planilla = $this->generar($this->deBaja('2023-08-01', '2026-10-31', 'renuncia'), 'renuncia');

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de prestación por renuncia voluntaria')
            ->assertSee('REN-'.str_pad((string) $planilla->id, 5, '0', STR_PAD_LEFT))
            ->assertSee('en concepto de prestación económica por renuncia voluntaria, por')
            ->assertSee('3 años y 92 días de servicio')
            ->assertSee('Firma y sello de la empleadora')
            ->assertDontSee('Recibo de indemnización');
    }

    // ── Lo de siempre no cambia ───────────────────────────────────────────

    public function test_la_quincena_de_la_baja_y_su_recibo_no_cambian(): void
    {
        // La última quincena la cobra activa, y después se va.
        $e = $this->persona('2021-08-01');
        $quincena = app(GenerarPlanilla::class)->generar($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-10-16', 'clase' => 'regular', 'moneda' => 'USD']);
        app(BajaEmpleado::class)->dar($this->actor, $e, '2026-10-31', 'despido');
        $planilla = $this->generar($e->fresh());

        // No se cruzan: la indemnización no paga tiempo de un período.
        $this->assertSame([], app(PrepararPlanilla::class)->avisosParaConfirmar($quincena->fresh(), true));
        $this->assertSame([], app(PrepararPlanilla::class)->avisosParaConfirmar($planilla->fresh(), true));

        $this->actingAs($this->actor)->get(route('planilla.impresos', ['planilla' => $quincena, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de pago')->assertSee('Período pagado')->assertSee('Entregó el pago')
            ->assertSee('No incluye aporte patronal, indemnización ni horas extra.')
            ->assertDontSee('Firma y sello de la empleadora')->assertDontSee('Tiempo de servicio')
            ->assertDontSee('Detalle del cálculo')->assertDontSee('Recibo de indemnización');
        $this->actingAs($this->actor)->get(route('planilla.show', $quincena))->assertOk()
            ->assertSee('Quincenal (dos por mes) · 2026-10-Q2');

        // Va después de la última quincena que cobró: se borra primero la indemnización.
        $this->assertSame($planilla->id, app(BorrarPlanilla::class)->posterior($quincena->fresh())?->id);
        $this->assertNull(app(BorrarPlanilla::class)->posterior($planilla->fresh()));
    }

    public function test_el_formulario_de_planilla_no_ofrece_indemnizacion(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.create'))->assertOk()
            ->assertSee('value="regular"', false)
            ->assertDontSee('value="indemnizacion"', false)->assertDontSee('value="renuncia"', false)->assertDontSee('value="vacaciones"', false);
    }

    // ── Revisión: la misma relación laboral, bajas repetidas y datos raros ─

    public function test_la_misma_relacion_laboral_no_se_indemniza_dos_veces(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-09-30', 'despido');
        $this->generar($e, 'indemnizacion', '2026-10-05');

        // Al reactivarla, se avisa que ya cobró.
        $this->actingAs($this->actor)->post(route('planilla.empleados.reactivar', $e))->assertSessionHasNoErrors()
            ->assertSessionHas('planilla.aviso', fn ($aviso) => str_contains($aviso,
                'Ya se le pagó indemnización por la baja del 30/09/2026: si vuelve a trabajar, poné su nueva fecha de ingreso.'));

        // Otra baja, otro mes: los mismos años desde el mismo ingreso no se pagan otra vez.
        app(BajaEmpleado::class)->dar($this->actor, $e->fresh(), '2026-11-05', 'despido');
        $e = $e->fresh();
        $this->assertSame('ya se generó el pago de esta baja (pago de indemnización)', $this->calculo($e)['fuera']);
        $this->assertThrows(fn () => app(PrepararPlanilla::class)->abrir($this->actor, $this->datos($e)), ValidationException::class, 'ya se generó el pago de esta baja');
        $this->assertThrows(fn () => $this->generar($e), ValidationException::class, 'ya se generó el pago de esta baja');
        $this->assertSame(1, Planilla::count());

        // Recontratación: con la nueva fecha de ingreso es otra relación, y se cuenta desde ahí.
        // Del 01/10/2026 al 05/11/2026: 36 días, menos de 15 días de indemnización → los 15 del mínimo.
        $e->update(['alta_el' => '2026-10-01']);
        $c = $this->calculo($e->fresh());
        $this->assertNull($c['fuera']);
        $this->assertSame('36 días', $c['tiempo']['texto']);
        $this->assertTrue($c['al_minimo']);
        $this->assertSame(20440, $c['centavos']);

        $segundo = $this->generar($e->fresh());
        $this->assertSame('204.40', (string) PlanillaDetalle::where('planilla_id', $segundo->id)->sole()->a_pagar);
        $this->assertSame(2, Planilla::where('estado', 'confirmada')->count());
    }

    public function test_a_quien_ya_esta_de_baja_no_se_le_da_de_baja_otra_vez(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        $this->actingAs($this->actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-11-05', 'motivo_baja' => 'renuncia'])
            ->assertSessionHasErrors(['empleado' => 'Esta persona ya está de baja. Para cambiar la fecha o el motivo, primero reactivala.']);

        $this->assertSame('2026-10-31', $e->fresh()->baja_el);
        $this->assertSame('despido', app(IndemnizacionBaja::class)->motivoDeBaja($e->fresh()));
        $this->assertSame(1, Activity::where('subject_id', $e->id)->where('description', 'dio de baja al empleado')->count());
    }

    public function test_la_persona_escrita_con_signo_tambien_se_bloquea_y_se_comprueba(): void
    {
        // «+5» es la persona 5 para la regla `integer`: tiene que pasar por lo mismo.
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'otro');
        $datos = ['empleado' => '+'.$e->id] + $this->datos($e);

        $this->assertSame($e->id, app(PrepararPlanilla::class)->bloquearPersona($datos)?->id);
        $this->assertThrows(fn () => app(GenerarPlanilla::class)->generar($this->actor, $datos), ValidationException::class, 'la baja fue por «Otro»');

        // En vacaciones, igual: quien no cumple el año no cobra por escribirse con signo.
        $bea = $this->persona('2026-06-01', '600.00', false, 'FICTICIO-OTRA', 'Bea');
        $vacaciones = ['tipo_periodo' => 'mensual', 'fecha' => '2026-11-06', 'clase' => 'vacaciones', 'moneda' => 'USD', 'fecha_pago' => '2026-11-06', 'empleado' => '+'.$bea->id];

        $this->assertSame($bea->id, app(PrepararPlanilla::class)->bloquearPersona($vacaciones)?->id);
        $this->assertThrows(fn () => app(GenerarPlanilla::class)->generar($this->actor, $vacaciones), ValidationException::class, 'no tiene vacaciones por pagar');
        $this->assertSame(0, Planilla::count());
    }

    public function test_si_falta_el_sueldo_se_carga_en_la_ficha_aunque_este_de_baja(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        PlanillaSueldo::where('planilla_empleado_id', $e->id)->delete();

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('Falta el sueldo a la fecha de baja para calcularlo.')
            ->assertSee('Cargalo en «Cambiar sueldo»')->assertDontSee('Revisar el pago');

        // La ficha de quien está de baja lo ofrece, vigente desde su ingreso y no desde hoy.
        $this->actingAs($this->actor)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Cambiar sueldo')
            ->assertSee('name="vigente_desde" type="date" value="2021-08-01"', false);

        $this->actingAs($this->actor)->post(route('planilla.empleados.sueldo', $e), ['sueldo_mensual' => '408.80', 'vigente_desde' => '2021-08-01'])
            ->assertSessionHasNoErrors();
        $this->assertSame(214704, $this->calculo($e->fresh())['centavos']);
    }

    public function test_si_falta_el_salario_minimo_dice_donde_se_carga(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');
        config(['planilla_descuentos.salario_minimo' => []]);

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('Falta el salario mínimo vigente a la fecha de baja para calcularlo.')
            ->assertSee('va en la configuración del salario mínimo')
            ->assertDontSee('Completalo en')->assertDontSee('Revisar el pago');
    }

    public function test_una_baja_de_un_mes_que_no_llego_dice_desde_cuando_se_paga(): void
    {
        // Del 01/08/2021 al 15/12/2026: 5 años y 137 días = 1,962 días × 1.12 = 2,197.44.
        $e = $this->deBaja('2021-08-01', '2026-12-15', 'despido');

        $this->actingAs($this->actor)->get(route('planilla.empleados.indemnizacion', $e))->assertOk()
            ->assertSee('2,197.44')
            ->assertSee('La baja es del 15/12/2026: el pago se genera a partir de ese mes.')
            ->assertDontSee('Revisar el pago')->assertDontSee('name="fecha_pago"', false);
    }

    // ── Permisos ──────────────────────────────────────────────────────────

    public function test_permisos(): void
    {
        $e = $this->deBaja('2021-08-01', '2026-10-31', 'despido');

        // Gestionar sin ver salarios, ver salarios sin gestionar, y solo ver: ninguno.
        foreach ([['planilla.ver', 'planilla.gestionar'], ['planilla.ver', 'planilla.salarios'], ['planilla.ver']] as $permisos) {
            $u = $this->usuario($permisos);
            $this->actingAs($u)->get(route('planilla.empleados.indemnizacion', $e))->assertForbidden();
            $this->actingAs($u)->get(route('planilla.revisar', $this->datos($e)))->assertForbidden();
            $this->actingAs($u)->post(route('planilla.store'), $this->datos($e))->assertForbidden();
            $this->assertThrows(fn () => $this->generar($e, quien: $u));
        }

        // Sin ver salarios, la ficha no ofrece calcularla, pero dice el motivo.
        $this->actingAs($this->usuario(['planilla.ver', 'planilla.gestionar']))->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Motivo de la baja:')->assertDontSee('Calcular indemnización');

        // Un usuario desactivado tampoco.
        $inactivo = $this->usuario(PermisoSistema::todos());
        $inactivo->update(['activo' => false]);
        $this->assertThrows(fn () => $this->generar($e, quien: $inactivo));

        // Dar de baja sigue pidiendo gestionar.
        $otra = $this->persona('2020-01-01', '600.00', false, 'FICTICIO-OTRA', 'Bea');
        $this->actingAs($this->usuario(['planilla.ver', 'planilla.salarios']))
            ->post(route('planilla.empleados.baja', $otra), ['fecha' => '2026-10-31', 'motivo_baja' => 'despido'])->assertForbidden();
        $this->assertTrue($otra->fresh()->activo);

        $this->assertSame(0, Planilla::count());
    }
}
