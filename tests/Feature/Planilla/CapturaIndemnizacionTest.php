<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use App\Services\Planilla\IndemnizacionBaja;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Solo para la captura: SQLite en memoria y datos ficticios. Fuera del repo. */
class CapturaIndemnizacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_captura(): void
    {
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true, 'gastos.enabled' => false, 'planilla.pagos_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        \App\Models\Empresa::create(['razon_social' => 'Patrona Ejemplo', 'nombre_comercial' => 'Negocio Ejemplo', 'nit' => '00000000000000', 'ambiente' => '00', 'activo' => true]);

        $actor = User::factory()->create(['activo' => true, 'name' => 'Usuario Ficticio']);
        $actor->syncPermissions(PermisoSistema::todos());

        $ana = app(AltaEmpleado::class)->registrar($actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2021-08-01', 'sueldo_mensual' => '408.80', 'cargo' => 'Vendedora',
            'sueldo_desde' => '2018-01-01', 'confirmar_bajo_minimo' => true,
        ]);
        app(BajaEmpleado::class)->dar($actor, $ana, '2026-10-31', 'despido');

        $dir = 'C:/Users/MELQUI~1/AppData/Local/Temp/claude/d43ef5fe39bb2864/0eefe29f-9345-4956-9115-938e771d7483/scratchpad/cap-indemnizacion/';

        file_put_contents($dir.'ficha.html', $this->actingAs($actor)->get(route('personal.empleados.show', 'p'.$ana->id))->assertOk()->getContent());
        file_put_contents($dir.'calculo.html', $this->actingAs($actor)->get(route('planilla.empleados.indemnizacion', $ana))->assertOk()->getContent());

        $planilla = app(IndemnizacionBaja::class)->generar($actor, $ana->fresh(), ['fecha_pago' => '2026-11-06']);
        file_put_contents($dir.'recibo.html', $this->actingAs($actor)->get(route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo']))->assertOk()->getContent());

        $this->assertSame('2147.04', (string) $planilla->detalles()->sole()->a_pagar);
    }
}
