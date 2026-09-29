<?php

namespace Tests\Feature\Gastos;

use App\Enums\AreaSistema;
use App\Enums\PermisoSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Gastos y pagos»: UN área con DOS módulos dentro, Gastos y Planilla.
 *
 * Antes eran dos áreas del selector superior. Se juntaron porque el selector se había
 * llenado de puertas y ninguna decía a quién le tocaba cuál.
 *
 * Lo que defiende este archivo es que juntar la NAVEGACIÓN no junta los PERMISOS. Es el
 * riesgo entero de este cambio: si alcanzara con `gastos.ver` para ver el menú de
 * Planilla, los sueldos quedarían a la vista de quien lleva las cuentas de proveedores.
 *
 * Y el riesgo del otro lado, menos obvio: quien solo tenía permisos de planilla ya
 * entraba a su área. Si la nueva exigiera `gastos.ver`, juntar la navegación le habría
 * QUITADO el acceso que tenía. Un área con dos puertas resuelve las dos cosas.
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

        // Los dos módulos encendidos: es el escenario del desarrollo habitual desde la
        // integración, y el único en que el candado de salarios se puede probar.
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

    // ═══════════ Un área, no dos ═══════════

    public function test_el_selector_ofrece_una_sola_puerta_llamada_gastos_y_pagos(): void
    {
        $this->assertSame('Gastos y pagos', AreaSistema::Gastos->label());

        // Planilla ya no es un área: si alguien reintroduce el case, esto lo caza.
        $etiquetas = array_map(fn (AreaSistema $a) => $a->label(), AreaSistema::cases());
        $this->assertNotContains('Planilla', $etiquetas);

        $admin = $this->usuario(PermisoSistema::cases());
        $visibles = AreaSistema::visiblesPara($admin);

        // Una sola entrada para los dos módulos.
        $this->assertSame(
            1,
            count(array_filter($visibles, fn (AreaSistema $a) => $a === AreaSistema::Gastos)),
            'El área Gastos y pagos tiene que aparecer una sola vez.'
        );
    }

    public function test_las_pantallas_de_planilla_pintan_la_barra_de_gastos_y_pagos(): void
    {
        $admin = $this->usuario(PermisoSistema::cases());

        $this->actingAs($admin)->get(route('planilla.index'))->assertOk();
        $this->assertSame(AreaSistema::Gastos, AreaSistema::activaDesdeRequest());

        $this->actingAs($admin)->get(route('gastos.panel'))->assertOk();
        $this->assertSame(AreaSistema::Gastos, AreaSistema::activaDesdeRequest());
    }

    // ═══════════ El candado de los salarios ═══════════

    public function test_quien_solo_tiene_gastos_no_ve_ni_el_titulo_del_grupo_planilla(): void
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

        // Sus cuatro filas de Gastos, sí.
        $this->assertStringContainsString(route('gastos.panel', [], false), $sidebar);
        $this->assertStringContainsString(route('gastos.pagos.index', [], false), $sidebar);

        // De Planilla, NADA: ni enlaces, ni el título del grupo. Que el menú exista ya
        // diría que hay sueldos que mirar.
        $this->assertStringNotContainsString('/planilla', $sidebar);
        $this->assertStringNotContainsString('Planilla</', $sidebar);
        $this->assertStringNotContainsString('>Personas', $sidebar);
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
        foreach (['planilla.index', 'planilla.empleados', 'planilla.anticipos', 'planilla.formatos'] as $ruta) {
            $this->actingAs($contable)->get(route($ruta))->assertForbidden();
        }
    }

    public function test_con_permiso_laboral_si_aparece_el_grupo_completo(): void
    {
        $admin = $this->usuario(PermisoSistema::cases());

        $sidebar = $this->sidebarDe(
            $this->actingAs($admin)->get(route('gastos.panel'))->assertOk()->getContent()
        );

        foreach (['planilla.index', 'planilla.empleados', 'planilla.anticipos', 'planilla.formatos'] as $ruta) {
            $this->assertStringContainsString(route($ruta, [], false), $sidebar, "Falta $ruta en el menú.");
        }

        // Y las de Gastos siguen ahí: no se cambió una barra por la otra.
        $this->assertStringContainsString(route('gastos.panel', [], false), $sidebar);
        $this->assertStringContainsString(route('gastos.informes', [], false), $sidebar);
    }

    // ═══════════ La otra puerta: solo planilla, sin gastos.ver ═══════════

    public function test_quien_solo_tiene_planilla_conserva_su_acceso_y_aterriza_en_planillas(): void
    {
        $encargada = $this->usuario([
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios,
            PermisoSistema::PlanillaGestionar, PermisoSistema::DashboardVer,
        ]);

        // El área se le ofrece, aunque no tenga `gastos.ver`.
        $this->assertContains(AreaSistema::Gastos, AreaSistema::visiblesPara($encargada));

        // Y aterriza en Planillas, no en «Por pagar», que para ella sería un 403.
        $this->assertSame('planilla.index', AreaSistema::Gastos->rutaInicioPara($encargada));

        $this->actingAs($encargada)->get(route('planilla.index'))->assertOk();
        // Lo de Gastos le sigue estando cerrado: comparten barra, no permisos.
        $this->actingAs($encargada)->get(route('gastos.panel'))->assertForbidden();
    }

    public function test_quien_solo_tiene_gastos_aterriza_en_por_pagar(): void
    {
        $contable = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::DashboardVer]);

        $this->assertSame('gastos.panel', AreaSistema::Gastos->rutaInicioPara($contable));
    }

    // ═══════════ Los interruptores, puerta por puerta ═══════════

    public function test_con_gastos_apagado_el_area_sigue_en_pie_por_planilla(): void
    {
        config()->set('gastos.enabled', false);

        $admin = $this->usuario(PermisoSistema::cases());

        $this->assertTrue(AreaSistema::Gastos->habilitada());
        $this->assertContains(AreaSistema::Gastos, AreaSistema::visiblesPara($admin));
        // Con esa puerta cerrada, el aterrizaje se corre a la que quedó abierta.
        $this->assertSame('planilla.index', AreaSistema::Gastos->rutaInicioPara($admin));
    }

    public function test_con_planilla_apagada_el_area_sigue_en_pie_por_gastos(): void
    {
        config()->set('planilla.enabled', false);

        $admin = $this->usuario(PermisoSistema::cases());

        $this->assertTrue(AreaSistema::Gastos->habilitada());
        $this->assertSame('gastos.panel', AreaSistema::Gastos->rutaInicioPara($admin));

        // Módulo apagado: el grupo no se dibuja aunque el permiso esté.
        $sidebar = $this->sidebarDe(
            $this->actingAs($admin)->get(route('gastos.panel'))->assertOk()->getContent()
        );
        $this->assertStringNotContainsString('/planilla', $sidebar);
    }

    public function test_con_los_dos_apagados_el_area_desaparece(): void
    {
        config()->set('gastos.enabled', false);
        config()->set('planilla.enabled', false);

        $admin = $this->usuario(PermisoSistema::cases());

        $this->assertFalse(AreaSistema::Gastos->habilitada());
        $this->assertNotContains(AreaSistema::Gastos, AreaSistema::visiblesPara($admin));
    }

    /**
     * El permiso de una puerta APAGADA no abre el área por la otra.
     *
     * Es el error que se comete al comprobar «¿área encendida?» y «¿algún permiso del
     * área?» por separado: alguien con solo `planilla.ver` y Planilla apagada entraría
     * porque Gastos está encendido, y se llevaría un 403 en la cara.
     */
    public function test_el_permiso_de_una_puerta_apagada_no_abre_la_otra(): void
    {
        config()->set('planilla.enabled', false);

        $encargada = $this->usuario([PermisoSistema::PlanillaVer, PermisoSistema::DashboardVer]);

        $this->assertNotContains(AreaSistema::Gastos, AreaSistema::visiblesPara($encargada));
    }
}
