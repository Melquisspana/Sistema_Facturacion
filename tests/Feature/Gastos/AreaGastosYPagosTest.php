<?php

namespace Tests\Feature\Gastos;

use App\Enums\AreaSistema;
use App\Enums\PermisoSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Personal\AreaPersonalTest;
use Tests\TestCase;

/**
 * «Gastos y pagos» ya no trae Planilla: se mudó al área Personal.
 *
 * Lo que defiende este archivo es que esa mudanza no deja nada de Planilla a la vista de
 * quien lleva las cuentas de proveedores. El área Personal se prueba en
 * {@see AreaPersonalTest}.
 */
class AreaGastosYPagosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('gastos.enabled', true);
        config()->set('planilla.enabled', true);
    }

    /** @param  array<int, PermisoSistema>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn (PermisoSistema $p) => $p->value, $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Solo el <aside>: es donde viven el selector de áreas y las secciones. */
    private function sidebarDe(string $html): string
    {
        $inicio = strpos($html, '<aside');
        $fin = strpos($html, '</aside>');
        $this->assertNotFalse($inicio, 'La página no tiene sidebar.');
        $this->assertNotFalse($fin, 'La sidebar no está cerrada.');

        return substr($html, $inicio, $fin - $inicio);
    }

    public function test_el_area_sigue_llamandose_gastos_y_pagos_y_planilla_no_es_un_area(): void
    {
        $this->assertSame('Gastos y pagos', AreaSistema::Gastos->label());

        $etiquetas = array_map(fn (AreaSistema $a) => $a->label(), AreaSistema::cases());
        $this->assertNotContains('Planilla', $etiquetas);
    }

    public function test_las_pantallas_de_gastos_pintan_su_barra(): void
    {
        $admin = $this->usuario(PermisoSistema::cases());

        $this->actingAs($admin)->get(route('gastos.panel'))->assertOk();
        $this->assertSame(AreaSistema::Gastos, AreaSistema::activaDesdeRequest());
    }

    public function test_quien_solo_tiene_gastos_no_ve_nada_de_planilla(): void
    {
        // El perfil realista: lleva proveedores, no tiene nada laboral.
        $contable = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosExportar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);

        $sidebar = $this->sidebarDe(
            $this->actingAs($contable)->get(route('gastos.panel'))->assertOk()->getContent()
        );

        $this->assertStringContainsString(route('gastos.panel', [], false), $sidebar);
        $this->assertStringContainsString(route('gastos.pagos.index', [], false), $sidebar);

        $this->assertStringNotContainsString('/planilla', $sidebar);
        $this->assertStringNotContainsString('>Anticipos', $sidebar);
    }

    public function test_y_tampoco_entra_por_la_url(): void
    {
        $contable = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosPagosRegistrar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);

        // Esconder no autoriza: el candado real es el middleware de cada ruta. Si algún
        // día el menú se equivoca, esto sigue cerrado.
        foreach (['planilla.index', 'planilla.empleados', 'planilla.anticipos', 'planilla.formatos', 'personal.empleados'] as $ruta) {
            $this->actingAs($contable)->get(route($ruta))->assertForbidden();
        }
    }

    public function test_quien_solo_tiene_gastos_aterriza_en_por_pagar(): void
    {
        $contable = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::DashboardVer]);

        $this->assertSame('gastos.panel', AreaSistema::Gastos->rutaInicioPara($contable));
    }

    public function test_con_gastos_apagado_el_area_no_se_ofrece(): void
    {
        config()->set('gastos.enabled', false);

        $admin = $this->usuario(PermisoSistema::cases());

        $this->assertFalse(AreaSistema::Gastos->habilitada());
        $this->assertNotContains(AreaSistema::Gastos, AreaSistema::visiblesPara($admin));
    }
}
