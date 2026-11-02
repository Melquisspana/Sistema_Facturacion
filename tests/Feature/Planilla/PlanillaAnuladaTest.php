<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\EstadoPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una planilla anulada no debe nada, igual que una pagada, y por eso se confundían: el
 * ajuste interno deja el saldo en cero, y un saldo en cero se leía como «pagado». Salía
 * «Pagado» en cada persona y «No cuadra» por todo el importe, sin que saliera un centavo.
 */
class PlanillaAnuladaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Planilla $planilla;

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

        app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '408.80',
        ]);

        $abierta = app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-11-02', 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-11-16',
        ]);

        $this->planilla = app(ConfirmarPlanilla::class)->confirmar($this->actor, $abierta->fresh());
    }

    private function anular(): Planilla
    {
        return app(AnularPlanilla::class)->anular($this->actor, $this->planilla, 'Prueba de anulación')->fresh();
    }

    public function test_confirmada_sin_pagar_sigue_pendiente(): void
    {
        $e = app(EstadoPlanilla::class)->deDetalle(PlanillaDetalle::where('planilla_id', $this->planilla->id)->sole());

        $this->assertSame('pendiente', $e['estado']);
        $this->assertSame(18345, $e['pendiente']);
    }

    public function test_cada_persona_y_cada_tercero_dicen_anulada_y_no_pagado(): void
    {
        $this->anular();
        $estado = app(EstadoPlanilla::class);

        $persona = $estado->deDetalle(PlanillaDetalle::where('planilla_id', $this->planilla->id)->sole());
        $this->assertSame('anulada', $persona['estado']);
        $this->assertSame(0, $persona['pagado']);
        $this->assertSame(0, $persona['pendiente']);

        foreach (PlanillaObligacionTercero::where('planilla_id', $this->planilla->id)->get() as $tercero) {
            $this->assertSame('anulada', $estado->deTercero($tercero)['estado']);
        }
    }

    public function test_el_resumen_no_cuenta_a_nadie_como_cobrado(): void
    {
        $avance = app(EstadoPlanilla::class)->dePlanilla($this->anular()->load('detalles'));

        $this->assertSame(0, $avance['personas_pagadas']);
        $this->assertSame(0, $avance['empleados_pagado']);
        $this->assertSame(0, $avance['empleados_pendiente']);
    }

    public function test_la_pantalla_dice_anulada_sin_diferencia_ni_desembolso(): void
    {
        $planilla = $this->anular();

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()
            ->assertSee('Anulada')
            ->assertSee('Nada que pagar')
            ->assertDontSee('No cuadra')
            ->assertDontSee('Cuadra:')
            ->assertDontSee('Dinero desembolsado')
            ->assertDontSee('personas cobradas')
            ->assertDontSee('>Pagado<', false);
    }

    public function test_la_pantalla_de_pagos_y_los_impresos_abren(): void
    {
        $planilla = $this->anular();

        $this->actingAs($this->actor);
        $this->get(route('planilla.pagos', $planilla))->assertOk()->assertDontSee('>Pagado<', false);
        $this->get(route('planilla.impresos', $planilla))->assertOk();
    }

    public function test_confirmada_sigue_mostrando_su_cuadre(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.show', $this->planilla))->assertOk()
            ->assertSee('Dinero desembolsado')
            ->assertSee('Cuadra:');
    }
}
