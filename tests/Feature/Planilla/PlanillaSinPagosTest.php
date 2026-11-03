<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Planilla sin el seguimiento de pagos (PLANILLA_PAGOS_ENABLED=false): mientras Gastos no
 * se usa, la planilla se confirma, se imprime y se firma, pero no pregunta quién cobró.
 */
class PlanillaSinPagosTest extends TestCase
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
        config(['planilla.enabled' => true, 'planilla.pagos_enabled' => false, 'gastos.enabled' => false]);

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

    public function test_la_ficha_no_muestra_pagos_ni_desembolsos(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.show', $this->planilla))->assertOk()
            ->assertDontSee(route('planilla.pagos', $this->planilla), false)
            ->assertDontSee('Avance del pago')
            ->assertDontSee('Dinero desembolsado')
            ->assertDontSee('queda 183.45')
            ->assertSee('Impresos');
    }

    public function test_las_rutas_de_pago_quedan_cerradas(): void
    {
        $detalle = PlanillaDetalle::where('planilla_id', $this->planilla->id)->sole();

        $this->actingAs($this->actor)->get(route('planilla.pagos', $this->planilla))->assertNotFound();
        $this->actingAs($this->actor)->post(route('planilla.pagar.persona', $detalle), [
            'importe' => '183.45', 'fecha' => '2026-11-16', 'metodo' => 'efectivo', 'pagado_por' => $this->actor->id,
        ])->assertNotFound();
    }

    public function test_el_impreso_sale_con_lo_que_recibe_cada_uno_y_boton_de_imprimir(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.impresos', $this->planilla))->assertOk()
            ->assertSee('window.print()', false)
            ->assertSee('Total recibido')
            ->assertSee('183.45')
            ->assertDontSee('Pago parcial');

        $this->actingAs($this->actor)->get(route('planilla.impresos', [$this->planilla, 'formato' => 'recibo']))->assertOk()
            ->assertSee('Recibo de pago', false)
            ->assertSee('Recibí conforme', false);
    }

    public function test_con_pagos_encendido_vuelve_todo(): void
    {
        config(['planilla.pagos_enabled' => true]);

        $this->actingAs($this->actor)->get(route('planilla.show', $this->planilla))->assertOk()
            ->assertSee(route('planilla.pagos', $this->planilla), false)
            ->assertSee('Avance del pago');
        $this->actingAs($this->actor)->get(route('planilla.pagos', $this->planilla))->assertOk();
    }
}
