<?php

namespace Tests\Feature\Configuracion;

use App\Enums\PermisoSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Atajos de Configuración a lo que se configura dentro de Producción y de Gastos.
 * Las pantallas se quedan en su módulo (con sus rutas y permisos): el índice solo
 * las ofrece cuando el módulo está encendido y el usuario puede abrirlas.
 */
class AtajosModulosConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private const MARCADOR_NAV = '<nav aria-label="Secciones de configuración"';

    private const CATALOGOS_PLANTA = [
        'planta.insumos.index', 'planta.lotes.index', 'planta.proveedores.index', 'planta.ubicaciones.index',
        'planta.productos-base.index', 'planta.presentaciones.index', 'planta.empaques.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function barra(User $usuario): string
    {
        $html = $this->actingAs($usuario)->get(route('configuracion.resumen'))->assertOk()->getContent();
        $inicio = strpos($html, self::MARCADOR_NAV);
        $this->assertNotFalse($inicio);

        return substr($html, $inicio, strpos($html, '</nav>', $inicio) - $inicio);
    }

    public function test_con_los_modulos_encendidos_el_admin_ve_los_atajos(): void
    {
        config(['planta.enabled' => true, 'gastos.enabled' => true]);

        $barra = $this->barra($this->admin());

        $this->assertStringContainsString('Producción', $barra);
        foreach (self::CATALOGOS_PLANTA as $ruta) {
            $this->assertStringContainsString('href="'.route($ruta).'"', $barra);
        }
        $this->assertStringContainsString('Configuración de empaque', $barra);
        $this->assertStringContainsString('href="'.route('gastos.reglas.index').'"', $barra);
        $this->assertStringContainsString('Gastos que se repiten', $barra);
    }

    public function test_con_los_modulos_apagados_no_hay_atajos(): void
    {
        config(['planta.enabled' => false, 'gastos.enabled' => false]);

        $barra = $this->barra($this->admin());

        $this->assertStringNotContainsString('/planta/', $barra);
        $this->assertStringNotContainsString('Gastos que se repiten', $barra);
    }

    public function test_sin_el_permiso_de_la_pantalla_no_se_ofrece_el_atajo(): void
    {
        config(['planta.enabled' => true, 'gastos.enabled' => true]);

        $usuario = User::factory()->create(['activo' => true])
            ->givePermissionTo([PermisoSistema::ConfiguracionGestionar->value, PermisoSistema::PlantaVer->value]);

        $barra = $this->barra($usuario);

        // Tiene planta.ver pero no planta.catalogos.ver, y no tiene gastos.ver.
        $this->assertStringNotContainsString('/planta/', $barra);
        $this->assertStringNotContainsString('Gastos que se repiten', $barra);
    }

    public function test_el_rol_produccion_sigue_entrando_a_sus_catalogos(): void
    {
        config(['planta.enabled' => true]);

        $produccion = User::factory()->create(['activo' => true])->assignRole('produccion');

        foreach (self::CATALOGOS_PLANTA as $ruta) {
            $this->actingAs($produccion)->get(route($ruta))->assertOk();
        }
        $this->actingAs($produccion)->get(route('configuracion.resumen'))->assertForbidden();
    }
}
