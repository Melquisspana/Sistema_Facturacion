<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Pago;
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
 * Producción arranca con `PLANILLA_ENABLED=true` y `GASTOS_ENABLED=false`: las tablas de
 * Gastos están, pero su pantalla no. Planilla tiene que confirmar, pagar y revertir igual,
 * y no puede ofrecer enlaces a una pantalla que responde 404.
 */
class PlanillaConGastosApagadoTest extends TestCase
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

    private function confirmada(): Planilla
    {
        app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '408.80',
        ]);

        $planilla = app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-11-02', 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-11-16',
        ]);

        return app(ConfirmarPlanilla::class)->confirmar($this->actor, $planilla->fresh());
    }

    public function test_confirma_paga_y_revierte_con_gastos_apagado(): void
    {
        $planilla = $this->confirmada();
        $detalle = PlanillaDetalle::where('planilla_id', $planilla->id)->sole();
        $this->assertNotNull($detalle->gasto_id);

        $this->actingAs($this->actor)->post(route('planilla.pagar.persona', $detalle), [
            'importe' => '183.45', 'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'pagado_por' => $this->actor->id, 'sin_comprobante' => 'Pagado en caja.',
        ])->assertSessionHasNoErrors();

        $pago = Pago::query()->sole();

        $this->actingAs($this->actor)->post(route('planilla.pagar.revertir', $pago), [
            'motivo' => 'Prueba de reversión con el módulo apagado',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($pago->fresh()->revertido_at);
    }

    public function test_no_ofrece_enlaces_a_gastos_si_esta_apagado(): void
    {
        $planilla = $this->confirmada();

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))
            ->assertOk()->assertDontSee('Ver obligación');

        config(['gastos.enabled' => true]);

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))
            ->assertOk()->assertSee('Ver obligación');
    }
}
