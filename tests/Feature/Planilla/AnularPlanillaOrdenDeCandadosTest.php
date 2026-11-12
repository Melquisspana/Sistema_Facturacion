<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ConsultasConCandado;
use Tests\TestCase;

/**
 * ANULAR UNA PLANILLA TOMA LOS CANDADOS EN EL MISMO ORDEN QUE UN PAGO.
 *
 * Registrar un pago bloquea primero a quien lo registra y después el gasto. Anular
 * bloqueaba el gasto y después escribía ajustes que apuntan a quien anula, y escribir una
 * fila que apunta a un usuario espera el candado de ese usuario. La misma persona pagando
 * y anulando a la vez (dos pestañas) podía quedar cada pedido esperando al otro, y MySQL
 * cortaba uno con un error. Ahora anular empieza por el mismo candado: se esperan en fila.
 * Datos ficticios.
 */
class AnularPlanillaOrdenDeCandadosTest extends TestCase
{
    use ConsultasConCandado;
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

    private function planillaConfirmada(): Planilla
    {
        app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '450.00',
            'sueldo_desde' => '2026-01-01', 'confirmar_bajo_minimo' => true,
        ]);
        $abierta = app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-10-01', 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-10-15',
        ]);

        return app(ConfirmarPlanilla::class)->confirmar($this->actor, $abierta->fresh());
    }

    public function test_anular_bloquea_primero_a_quien_anula_y_despues_planilla_gasto_y_cuota(): void
    {
        $planilla = $this->planillaConfirmada();
        $consultas = $this->consultasEnTransaccion();

        app(AnularPlanilla::class)->anular($this->actor, $planilla, 'Se generó con un error.');

        $conCandado = array_values(array_filter(array_column($consultas->getArrayCopy(), 'sql'),
            fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'for update')));
        $orden = [];
        foreach (['users', 'planillas', 'gastos', 'gastos_cuotas'] as $tabla) {
            foreach ($conCandado as $i => $sql) {
                if (preg_match('/from [`"]'.$tabla.'[`"]/', $sql) === 1) {
                    $orden[$tabla] = $i;
                    break;
                }
            }
        }

        $this->assertSame(['users', 'planillas', 'gastos', 'gastos_cuotas'], array_keys($orden), 'Falta alguno de los candados.');
        $this->assertSame(0, $orden['users'], 'El primer candado de la anulación tiene que ser el de quien anula.');
        $this->assertLessThan($orden['gastos'], $orden['planillas']);
        $this->assertLessThan($orden['gastos_cuotas'], $orden['gastos']);
        // Y lo primero que LEE la transacción es ese candado.
        $this->assertTrue($this->nombra($consultas[0]['sql'], 'users') && str_contains($consultas[0]['sql'], 'for update'));
        $this->assertSame('anulada', $planilla->fresh()->estado);
    }
}
