<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sidebar (layouts/navigation.blade.php + layouts/partials/sidebar-*): jerarquía
 * de categorías, textos visibles, grupos colapsables, estado activo y —lo más
 * importante— PARIDAD DE ACCESOS: la reorganización visual no le dio ni le quitó
 * un solo enlace a ningún rol.
 *
 * Todo lo de acá es PRESENTACIÓN. Que un enlace se dibuje o no nunca sustituye al
 * candado de backend (middleware `permission:` + policies), que se prueba en
 * Tests\Feature\Autorizacion\RolesPermisosTest.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad', 'produccion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** Devuelve solo el elemento <aside> (la sidebar) del HTML de una respuesta. */
    private function sidebarDe(string $html): string
    {
        $inicio = strpos($html, '<aside');
        $fin = strpos($html, '</aside>');

        $this->assertNotFalse($inicio, 'La página no tiene sidebar.');
        $this->assertNotFalse($fin, 'La sidebar no está cerrada.');

        return substr($html, $inicio, $fin - $inicio);
    }

    /**
     * Todos los href del sidebar, sin repetir y ordenados. Es la forma de comparar
     * "qué puertas ve este rol" contra una lista explícita.
     *
     * @return array<int, string>
     */
    private function enlacesDelSidebar(string $html): array
    {
        preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $this->sidebarDe($html), $m);

        $enlaces = array_values(array_unique($m[1]));
        sort($enlaces);

        return $enlaces;
    }

    /**
     * @param  array<int, string>  $rutas  nombres de ruta esperados
     * @return array<int, string>
     */
    private function urls(array $rutas): array
    {
        $urls = array_map(static fn (string $r) => route($r), $rutas);
        sort($urls);

        return $urls;
    }

    // ------------------------------------------------------------------ estructura

    public function test_categorias_de_la_estructura_nueva(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        foreach ([
            'Inicio',
            'Ventas y facturación', 'Comercial', 'Facturación',
            'Cobros', 'Prontos Pagos',
            'Contabilidad', 'Exportaciones',
            'Administración', 'Configuración', 'Sistema',
        ] as $categoria) {
            $resp->assertSee($categoria);
        }
    }

    public function test_textos_visibles_renombrados(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        // «Clientes» y «Productos» a secas: el rótulo del enlace, no un texto suelto.
        foreach (['Clientes', 'Productos'] as $rotulo) {
            $this->assertMatchesRegularExpression(
                '/>\s*'.preg_quote($rotulo, '/').'\s*</u',
                $sidebar,
                "Falta el enlace rotulado «{$rotulo}» en la sidebar.",
            );
        }

        foreach (['Documentos fiscales', 'Preparar emisión real', 'Clientes y precios', 'Catálogo de productos'] as $texto) {
            $this->assertStringContainsString($texto, $sidebar, "Falta el texto «{$texto}» en la sidebar.");
        }

        // Los rótulos viejos ya no están.
        $this->assertStringNotContainsString('Clientes de facturación', $sidebar);
        $this->assertStringNotContainsString('Perfiles y precios de exportación', $sidebar);
    }

    /**
     * «Nueva lista de empaque» sale del sidebar (crear es una acción, no una
     * sección) pero NO desaparece: sigue como botón del listado para quien tiene
     * exportaciones.gestionar. Si algún día se quita ese botón, esta prueba avisa.
     */
    public function test_crear_lista_de_empaque_sale_del_sidebar_pero_sigue_en_el_listado(): void
    {
        $usuario = $this->usuario('administrador');

        $sidebar = $this->sidebarDe(
            $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent()
        );
        $this->assertStringNotContainsString(route('exportaciones.create', [], false), $sidebar);

        $this->actingAs($usuario)->get(route('exportaciones.index'))->assertOk()
            ->assertSee(route('exportaciones.create'), false)
            ->assertSee('Nueva lista de empaque');
    }

    /** El hueco de navegación de Configuración > Correo queda cerrado. */
    public function test_configuracion_correo_es_alcanzable_desde_sidebar_y_pestanas(): void
    {
        $usuario = $this->usuario('administrador');

        $sidebar = $this->sidebarDe(
            $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent()
        );
        $this->assertStringContainsString(route('configuracion.correo.edit', [], false), $sidebar);

        // Y también como pestaña dentro de la propia pantalla de configuración.
        $this->actingAs($usuario)->get(route('configuracion.empresa.edit'))->assertOk()
            ->assertSee(route('configuracion.correo.edit'), false)
            ->assertSee('Correo');
    }

    /** Quien no es administrador no ve la pestaña ni el enlace de Correo. */
    public function test_configuracion_correo_no_aparece_para_los_demas_roles(): void
    {
        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $sidebar = $this->sidebarDe(
                $this->actingAs($this->usuario($rol))->get(route('dashboard'))->assertOk()->getContent()
            );

            $this->assertStringNotContainsString(route('configuracion.correo.edit', [], false), $sidebar, "El rol {$rol} no debe ver Configuración > Correo.");
        }

        // Y la URL sigue cerrada en backend, que es el candado de verdad.
        $this->actingAs($this->usuario('facturacion'))->get(route('configuracion.correo.edit'))->assertForbidden();
    }

    // ------------------------------------------------------------------ paridad por rol

    /**
     * PARIDAD: el conjunto EXACTO de enlaces del sidebar por rol. Es la prueba que
     * demuestra que la reorganización no agregó ni quitó accesos a nadie. Si un
     * cambio futuro suma un enlace para un rol, esta lista lo obliga a decirlo.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function enlacesEsperadosPorRol(): array
    {
        $lecturaOperativa = [
            'dashboard',
            'clientes.index', 'productos.index', 'facturacion.index',
            'ppq.index', 'ppq.lotes.index',
            'documentos-recibidos.index', 'facturacion.reporte-contadora', 'contabilidad.paquete',
            'exportaciones.index', 'exportaciones.clientes.index', 'exportaciones.productos.index',
        ];

        return [
            'administrador' => ['administrador', [
                ...$lecturaOperativa,
                'facturacion.preparar-produccion',
                'usuarios.index', 'auditoria.index', 'importaciones.index',
                'configuracion.empresa.edit', 'configuracion.establecimientos.index',
                'configuracion.puntos-venta.index', 'configuracion.correlativos.index',
                'configuracion.contabilidad.edit', 'configuracion.correo.edit',
                'admin.salud-sistema',
            ]],
            // Jefatura: lectura amplia, sin preparación ni administración.
            'jefatura' => ['jefatura', $lecturaOperativa],
            // Facturación: lo mismo + el checklist de emisión real (preparacion.ver).
            'facturacion' => ['facturacion', [...$lecturaOperativa, 'facturacion.preparar-produccion']],
            // Contabilidad: lo mismo + auditoría (auditoria.ver).
            'contabilidad' => ['contabilidad', [...$lecturaOperativa, 'auditoria.index']],
        ];
    }

    /**
     * @param  array<int, string>  $rutas
     */
    #[DataProvider('enlacesEsperadosPorRol')]
    public function test_cada_rol_ve_exactamente_los_mismos_enlaces_de_siempre(string $rol, array $rutas): void
    {
        $html = $this->actingAs($this->usuario($rol))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(
            $this->urls($rutas),
            $this->enlacesDelSidebar($html),
            "El sidebar del rol {$rol} cambió de enlaces.",
        );
    }

    /** El rol de producción ve su área y nada más: mismos enlaces que antes. */
    public function test_produccion_ve_exactamente_los_enlaces_de_su_area(): void
    {
        config()->set('planta.enabled', true);

        $html = $this->actingAs($this->usuario('produccion'))->get(route('planta.dashboard'))->assertOk()->getContent();

        $this->assertSame(
            $this->urls([
                'planta.dashboard',
                'planta.existencias.index', 'planta.recepciones.index', 'planta.traslados.index',
                'planta.disponibilidad.index', 'planta.ajustes.index', 'planta.movimientos.index',
                'planta.insumos.index', 'planta.lotes.index', 'planta.proveedores.index',
                'planta.ubicaciones.index', 'planta.productos-base.index',
                'planta.presentaciones.index', 'planta.empaques.index',
            ]),
            $this->enlacesDelSidebar($html),
        );
    }

    /** El área Cobros presenta salidas, bandeja, rutas y —visualmente— PPQ. */
    public function test_el_area_cobros_presenta_sus_enlaces_mas_prontos_pagos(): void
    {
        $html = $this->actingAs($this->usuario('administrador'))->get(route('rutas.dashboard'))->assertOk()->getContent();

        $this->assertSame(
            $this->urls([
                'rutas.dashboard', 'rutas.salidas.index', 'rutas.documentos.index', 'rutas.rutas.index',
                'ppq.index', 'ppq.lotes.index',
            ]),
            $this->enlacesDelSidebar($html),
        );

        $sidebar = $this->sidebarDe($html);
        $this->assertStringContainsString('Resumen', $sidebar);
        $this->assertStringContainsString('Documentos por cobrar', $sidebar);
        $this->assertStringContainsString('Prontos Pagos', $sidebar);
    }

    public function test_jefatura_ve_secciones_operativas_de_lectura_pero_no_administracion(): void
    {
        // Jefatura tiene lectura amplia (ve PPQ, Contabilidad y Exportaciones), pero
        // NO Administración, Configuración ni Sistema.
        $resp = $this->actingAs($this->usuario('jefatura'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Prontos Pagos');
        $resp->assertSee('Contabilidad');
        $resp->assertSee('Exportaciones');
        $resp->assertDontSee('Administración');
        $resp->assertDontSee(route('usuarios.index'), false);
        $resp->assertDontSee(route('admin.salud-sistema'), false);
        $resp->assertDontSee(route('configuracion.empresa.edit'), false);
    }

    public function test_administrador_ve_administracion_con_badge_de_jobs_fallidos(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'sync', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Salud del sistema');
        $resp->assertSeeInOrder(['Salud del sistema', '1']);
    }

    /**
     * «Salud del sistema» es infraestructura, no administración de negocio: va en su
     * propio bloque «Sistema», DESPUÉS de Administración y Configuración.
     */
    public function test_salud_del_sistema_esta_separada_de_administracion(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Administración', 'Configuración', 'Sistema', 'Salud del sistema']);
    }

    // ------------------------------------------------------------------ rutas y estado activo

    public function test_enlaces_del_menu_apuntan_a_las_mismas_rutas_de_siempre(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        foreach ([
            'clientes.index', 'productos.index', 'facturacion.index', 'facturacion.preparar-produccion',
            'ppq.index', 'ppq.lotes.index', 'documentos-recibidos.index', 'facturacion.reporte-contadora',
            'contabilidad.paquete', 'exportaciones.index', 'exportaciones.clientes.index',
            'exportaciones.productos.index', 'usuarios.index', 'auditoria.index', 'importaciones.index',
            'configuracion.empresa.edit', 'admin.salud-sistema',
        ] as $ruta) {
            $resp->assertSee(route($ruta), false);
        }
    }

    public function test_ruta_activa_se_marca_con_aria_current(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('exportaciones.clientes.index'))
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    // ------------------------------------------------------------------ colapsables

    /**
     * El grupo que contiene la página actual nace ABIERTO, pase lo que pase en
     * localStorage: se resuelve en servidor (`abierto: true || …`), así que la
     * página en la que estás nunca queda escondida dentro de un grupo cerrado.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function grupoDeCadaRuta(): array
    {
        return [
            'ventas / clientes' => ['clientes.index', 'ventas'],
            'ventas / documentos fiscales' => ['facturacion.index', 'ventas'],
            'cobros / ppq' => ['ppq.index', 'cobros'],
            'contabilidad / compras' => ['documentos-recibidos.index', 'contabilidad'],
            'contabilidad / ventas' => ['facturacion.reporte-contadora', 'contabilidad'],
            'exportaciones / listas' => ['exportaciones.index', 'exportaciones'],
            'administracion / usuarios' => ['usuarios.index', 'administracion'],
            'configuracion / empresa' => ['configuracion.empresa.edit', 'configuracion'],
        ];
    }

    #[DataProvider('grupoDeCadaRuta')]
    public function test_el_grupo_de_la_ruta_actual_nace_abierto(string $ruta, string $grupo): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route($ruta))->assertOk()->getContent()
        );

        $this->assertStringContainsString("aria-controls=\"sidebar-grupo-{$grupo}\"", $sidebar);
        $this->assertMatchesRegularExpression(
            '/abierto:\s*true\s*\|\|/',
            $sidebar,
            "El grupo «{$grupo}» debería nacer abierto en la ruta {$ruta}.",
        );
    }

    /** Un grupo que NO contiene la ruta actual queda a merced de localStorage. */
    public function test_los_grupos_inactivos_no_se_fuerzan_abiertos(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        // En el dashboard ningún grupo colapsable contiene la ruta activa.
        $this->assertStringNotContainsString('abierto: true ||', $sidebar);
        $this->assertStringContainsString('abierto: false ||', $sidebar);
    }

    /** Los colapsables son accesibles: botón con aria-expanded sobre un panel con id. */
    public function test_los_grupos_colapsables_son_accesibles(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        $this->assertStringContainsString(':aria-expanded="abierto ? \'true\' : \'false\'"', $sidebar);
        $this->assertStringContainsString('id="sidebar-grupo-ventas"', $sidebar);
        $this->assertStringContainsString('<button type="button"', $sidebar);
    }

    /**
     * El sidebar móvil (off-canvas) sigue intacto: los colapsables viven DENTRO del
     * mismo <aside> que abre y cierra la hamburguesa, sin tocar ese mecanismo.
     */
    public function test_el_sidebar_movil_sigue_funcionando(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('sidebarAbierta = ! sidebarAbierta', false);
        $resp->assertSee('x-data="{ sidebarAbierta: false }"', false);
        $resp->assertSee('overflow-y-auto', false);
    }

    public function test_sidebar_tiene_boton_de_tema_y_es_desplazable(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Claro / oscuro', false);
        $resp->assertSee('overflow-y-auto', false);
    }
}
