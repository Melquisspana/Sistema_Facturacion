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
use App\Services\Planilla\SueldoHabitual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El tope del ISSS es POR MES (Melqui, 12/11/2026): lo cotizado en un mismo mes, sumando
 * quincenas y vacaciones, no pasa de 3 % de US$1,000 = US$30.00, y se descuenta repartido
 * en los pagos. Valores escritos a mano, con la cuenta al lado. Datos ficticios.
 */
class TopeIsssPorMesTest extends TestCase
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

    /** Con descuentos de ley. Entró en octubre de 2025: en octubre de 2026 le tocan vacaciones. */
    private function persona(string $sueldo): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2025-10-01', 'sueldo_mensual' => $sueldo, 'sueldo_desde' => '2025-10-01',
        ]);
    }

    private function generar(array $datos): Planilla
    {
        return app(GenerarPlanilla::class)->generar($this->actor, $datos + ['clase' => 'regular', 'moneda' => 'USD']);
    }

    private function quincena(string $fecha): Planilla
    {
        return $this->generar(['tipo_periodo' => 'quincenal', 'fecha' => $fecha]);
    }

    private function vacaciones(PlanillaEmpleado $e, string $pago): Planilla
    {
        return $this->generar(['tipo_periodo' => 'mensual', 'fecha' => $pago, 'clase' => 'vacaciones', 'fecha_pago' => $pago, 'empleado' => $e->id]);
    }

    private function isss(Planilla $planilla, PlanillaEmpleado $e): string
    {
        $this->assertSame('confirmada', $planilla->fresh()->estado);

        return (string) PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $e->id)->sole()
            ->conceptos()->where('ley', 'isss')->value('importe');
    }

    public function test_un_mes_normal_de_dos_quincenas_iguales_no_cambia(): void
    {
        $ana = $this->persona('1200.00');

        // 600.00 por quincena: cada una cotiza sobre 500.00, 3 % = 15.00.
        $this->assertSame('15.00', $this->isss($this->quincena('2026-10-01'), $ana));
        $this->assertSame('15.00', $this->isss($this->quincena('2026-10-16'), $ana));
    }

    public function test_una_quincena_que_sola_llega_al_tope_no_se_lleva_el_mes_entero(): void
    {
        $ana = $this->persona('2000.00');

        // 1,000.00 por quincena: la primera no cotiza los 30.00 del mes, cotiza su mitad.
        $this->assertSame('15.00', $this->isss($this->quincena('2026-10-01'), $ana));
        $this->assertSame('15.00', $this->isss($this->quincena('2026-10-16'), $ana));
    }

    public function test_un_sueldo_bajo_el_tope_cotiza_igual_que_siempre(): void
    {
        $ana = $this->persona('408.80');

        // 204.40 × 3 % = 6.132: 6.13 en cada quincena.
        $this->assertSame('6.13', $this->isss($this->quincena('2026-10-01'), $ana));
        $this->assertSame('6.13', $this->isss($this->quincena('2026-10-16'), $ana));
    }

    public function test_con_vacaciones_en_el_mes_no_pasa_del_tope(): void
    {
        $ana = $this->persona('1200.00');

        // Vacaciones: 1200 ÷ 30 × 15 × 1.30 = 780.00. Primera quincena 15.00 y vacaciones
        // 15.00: el mes ya llegó a 30.00 y la segunda quincena no cotiza más.
        $this->assertSame('15.00', $this->isss($this->quincena('2026-10-01'), $ana));
        $this->assertSame('15.00', $this->isss($this->vacaciones($ana, '2026-10-10'), $ana));
        $this->assertSame('0.00', $this->isss($this->quincena('2026-10-16'), $ana));
    }

    public function test_vacaciones_despues_de_las_dos_quincenas_no_cotizan_mas(): void
    {
        $ana = $this->persona('1200.00');

        $this->quincena('2026-10-01');
        $this->quincena('2026-10-16');

        $this->assertSame('0.00', $this->isss($this->vacaciones($ana, '2026-10-31'), $ana));
    }

    private function borrador(string $fecha): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, ['tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '']);
    }

    private function isssDelBorrador(Planilla $planilla, PlanillaEmpleado $e): string
    {
        return (string) PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $e->id)->sole()
            ->conceptos()->where('ley', 'isss')->value('importe');
    }

    public function test_un_borrador_viejo_se_ajusta_al_confirmar_si_otro_pago_del_mes_ya_cotizo(): void
    {
        $ana = $this->persona('1200.00');
        $this->quincena('2026-10-01');
        $segunda = $this->borrador('2026-10-16');
        $this->assertSame('15.00', $this->isssDelBorrador($segunda, $ana));

        // Mientras el borrador espera, se pagan las vacaciones: el mes llega a 30.00.
        $this->vacaciones($ana, '2026-10-10');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $segunda->fresh());

        // Lo propuso el sistema y nadie lo tocó: se ajusta solo. 600 − 43.50 de AFP − 0 de ISSS
        // = 556.50 gravado, tramo III: 30.00 + 20 % × (556.50 − 447.62) = 51.78 de renta.
        $this->assertSame('0.00', $this->isss($segunda, $ana));
        $this->assertSame('504.72', (string) PlanillaDetalle::where('planilla_id', $segunda->id)->sole()->a_pagar);
    }

    public function test_un_borrador_viejo_vuelve_a_cotizar_si_se_anulo_el_pago_que_llego_al_tope(): void
    {
        $ana = $this->persona('1200.00');
        $this->quincena('2026-10-01');
        $vacaciones = $this->vacaciones($ana, '2026-10-10');
        $segunda = $this->borrador('2026-10-16');
        $this->assertSame('0.00', $this->isssDelBorrador($segunda, $ana));

        app(AnularPlanilla::class)->anular($this->actor, $vacaciones, 'Se pagaron con la fecha equivocada.');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $segunda->fresh());

        $this->assertSame('15.00', $this->isss($segunda, $ana));
    }

    public function test_una_correccion_a_mano_no_se_pisa_y_frena_para_revisarla(): void
    {
        $ana = $this->persona('1200.00');
        $this->quincena('2026-10-01');
        $segunda = $this->borrador('2026-10-16');
        $detalle = PlanillaDetalle::where('planilla_id', $segunda->id)->sole();

        app(PrepararPlanilla::class)->guardarLineas($this->actor, $segunda, [[
            'planilla_empleado_id' => $ana->id, 'salario' => (string) $detalle->salario,
            'conceptos' => $detalle->conceptos()->get()->map(fn ($c) => [
                'tipo' => $c->tipo, 'ley' => $c->ley, 'concepto' => $c->concepto,
                'importe' => $c->ley === 'isss' ? '14.00' : (string) $c->importe, 'destino' => $c->destino, 'tercero' => $c->tercero,
            ])->all(),
        ]]);

        $this->vacaciones($ana, '2026-10-10');

        try {
            app(ConfirmarPlanilla::class)->confirmar($this->actor, $segunda->fresh());
            $this->fail('Tenía que frenar.');
        } catch (ValidationException $e) {
            $this->assertContains('Ana Ficticia: el ISSS se corrigió a mano a 14.00, y con lo ya cotizado en el mes ahora se propone 0.00. Revisalo.', $e->errors()['reparos']);
        }

        $this->assertSame('borrador', $segunda->fresh()->estado);
        $this->assertSame('14.00', $this->isssDelBorrador($segunda, $ana));
    }

    public function test_un_cambio_de_sueldo_a_mitad_de_mes_completa_el_tope_en_el_segundo_pago(): void
    {
        $ana = $this->persona('800.00');
        app(SueldoHabitual::class)->registrarMensual($this->actor, $ana, ['sueldo_mensual' => '1400.00', 'vigente_desde' => '2026-10-16']);

        // 400.00 × 3 % = 12.00. En el mes cobra 400 + 700 = 1,100, más que el tope: el
        // segundo pago completa 30.00 − 12.00 = 18.00.
        $this->assertSame('12.00', $this->isss($this->quincena('2026-10-01'), $ana));
        $this->assertSame('18.00', $this->isss($this->quincena('2026-10-16'), $ana));
    }
}
