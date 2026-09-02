<?php

namespace Tests\Feature;

use App\Models\Cliente;
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
     * La sidebar SIN el selector de áreas: la lista de secciones del área activa.
     * Elegir un área y navegar dentro de una son operaciones distintas, y algunos
     * nombres viven legítimamente en las dos (el área «Cobros» y el grupo «Pronto
     * pago» hablan de cosas distintas aunque se parezcan).
     */
    private function seccionesDelSidebar(string $html): string
    {
        $sidebar = $this->sidebarDe($html);
        $inicio = strpos($sidebar, '<nav aria-label="Áreas de trabajo"');

        if ($inicio === false) {
            return $sidebar;
        }

        $fin = strpos($sidebar, '</nav>', $inicio);
        $this->assertNotFalse($fin, 'El selector de áreas no está cerrado.');

        return substr_replace($sidebar, '', $inicio, $fin - $inicio + 6);
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
            'Ventas y facturación',
            'Pronto pago',
            'Contabilidad',
            'Administración', 'Sistema',
        ] as $categoria) {
            $resp->assertSee($categoria);
        }

        // «Exportaciones» dejó de ser un grupo del menú: sus tres destinos se
        // reubicaron (Productos, ficha del cliente, Ventas y facturación) y ninguno
        // quedó sin puerta — ver test_los_destinos_de_exportaciones_siguen_alcanzables.
        $sidebar = $this->sidebarDe($resp->getContent());
        $this->assertDoesNotMatchRegularExpression('/>\s*Exportaciones\s*</u', $sidebar);
    }

    /**
     * Ningún destino del antiguo grupo «Exportaciones» quedó inaccesible. Es la
     * condición que permitió retirar el grupo: no basta con que las rutas existan,
     * tienen que estar a un clic desde el menú actual.
     */
    public function test_los_destinos_de_exportaciones_siguen_alcanzables(): void
    {
        $usuario = $this->usuario('administrador');
        $sidebar = $this->sidebarDe(
            $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent()
        );

        // Listas de empaque: fila propia dentro de Ventas y facturación.
        $this->assertStringContainsString(route('facturacion.listas.index', [], false), $sidebar);
        $this->assertStringContainsString('Listas de empaque', $sidebar);

        // Productos de exportación: pestaña dentro de la entrada única «Productos».
        $this->assertStringContainsString(route('productos.index', [], false), $sidebar);
        $this->actingAs($usuario)->get(route('productos.index'))->assertOk()
            ->assertSee(route('productos.exportacion.index'), false)
            ->assertSee('Productos de exportación');

        // Clientes de exportación: bloque dentro de la ficha del cliente.
        $cliente = Cliente::factory()->exportacion()->create();
        $this->actingAs($usuario)->get(route('clientes.show', $cliente))->assertOk()
            ->assertSee('Exportación');
    }

    /**
     * Configuración es una ENTRADA, no una categoría: una fila directa que abre el
     * Centro de Configuración.
     *
     * Estuvo un rato siendo un <x-sidebar-group> de una sola opción, y el resultado
     * era que la barra decía «Configuración» dos veces seguidas —una en el rótulo de
     * la sección y otra en la fila de debajo—. Un grupo existe para agrupar; con un
     * solo hijo el encabezado no aporta jerarquía, sólo repetición.
     *
     * Esta prueba fija las tres cosas que definen la decisión: la fila existe y lleva
     * al resumen, no hay encabezado de grupo llamado «Configuración» sobre ella, y no
     * es colapsable (no tiene panel con id ni botón que lo controle).
     */
    public function test_configuracion_es_una_entrada_directa_y_no_un_grupo(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        // La fila existe y apunta al Centro de Configuración.
        $this->assertStringContainsString(route('configuracion.resumen', [], false), $sidebar);
        $this->assertStringContainsString('>Configuración<', $sidebar);

        // No es colapsable: ni panel con id ni botón que lo controle.
        $this->assertStringNotContainsString('sidebar-grupo-configuracion', $sidebar);

        // Y «Configuración» aparece UNA sola vez en el sidebar: si volviera a
        // envolverse en un grupo, el encabezado la repetiría y esto lo delataría.
        $this->assertSame(
            1,
            substr_count($sidebar, '>Configuración<'),
            'El sidebar no debe decir «Configuración» más de una vez.',
        );
    }

    /** Y estando dentro del módulo, la entrada se marca como activa. */
    public function test_la_entrada_de_configuracion_se_marca_activa_en_todo_el_modulo(): void
    {
        foreach (['configuracion.resumen', 'configuracion.fiscal.hacienda', 'configuracion.sistema'] as $ruta) {
            $sidebar = $this->sidebarDe(
                $this->actingAs($this->usuario('administrador'))->get(route($ruta))->assertOk()->getContent()
            );

            $this->assertMatchesRegularExpression(
                '/<a[^>]*'.preg_quote(route('configuracion.resumen', [], false), '/').'[^>]*aria-current="page"/s',
                $sidebar,
                "La entrada de Configuración debería marcarse activa en {$ruta}.",
            );
        }
    }

    /**
     * «Ventas y facturación» es UNA lista plana. Los sub-bloques «Comercial» y
     * «Facturación» desaparecen: el grupo ya se llama «Ventas y facturación» y
     * repetir «Facturación» dentro no distinguía nada.
     *
     * Se comprueba sobre el <aside> y no sobre la página entera porque la palabra
     * «Facturación» sigue existiendo con otros significados —es la etiqueta del
     * área en el selector superior—, y esta prueba habla del sidebar.
     */
    public function test_ventas_y_facturacion_es_una_lista_plana(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        // El componente de sub-bloque ya no se usa en ningún grupo del sidebar.
        $this->assertStringNotContainsString('>Comercial<', $sidebar);
        $this->assertDoesNotMatchRegularExpression(
            '/text-\[10px\][^>]*>\s*Facturación\s*</u',
            $sidebar,
            'El subtítulo «Facturación» sigue dentro del grupo de ventas.',
        );

        // Y el orden es el acordado: el documento primero, los catálogos después.
        $this->assertMatchesRegularExpression(
            '/Documentos fiscales.*Clientes.*Productos/su',
            $sidebar,
            'El orden de «Ventas y facturación» debe ser Documentos fiscales → Clientes → Productos.',
        );
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

        foreach (['Documentos fiscales', 'Listas de empaque'] as $texto) {
            $this->assertStringContainsString($texto, $sidebar, "Falta el texto «{$texto}» en la sidebar.");
        }

        // Las dos filas del grupo retirado ya no tienen rótulo propio: «Clientes y
        // precios» vive dentro de la ficha del cliente y «Catálogo de productos» es la
        // segunda pestaña de Productos.
        $this->assertStringNotContainsString('Clientes y precios', $sidebar);
        $this->assertStringNotContainsString('Catálogo de productos', $sidebar);

        // Los rótulos viejos ya no están.
        $this->assertStringNotContainsString('Clientes de facturación', $sidebar);
        $this->assertStringNotContainsString('Perfiles y precios de exportación', $sidebar);
        // «Preparar emisión real» era el guion de la primera emisión, no una
        // herramienta diaria: sale del menú. La pantalla NO se retiró — ver
        // test_preparar_emision_real_sigue_accesible_por_url_para_los_roles_autorizados.
        $this->assertStringNotContainsString('Preparar emisión real', $sidebar);
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
        $this->assertStringNotContainsString(route('facturacion.listas.create', [], false), $sidebar);

        $this->actingAs($usuario)->get(route('facturacion.listas.index'))->assertOk()
            ->assertSee(route('facturacion.listas.create'), false)
            ->assertSee('Nueva lista de empaque');
    }

    /**
     * Correo sigue siendo alcanzable, pero por el camino nuevo: el sidebar ya no
     * lleva a seis pantallas sueltas de configuración, lleva al Centro de
     * Configuración, y desde ahí está el índice completo.
     */
    public function test_configuracion_correo_es_alcanzable_desde_el_centro_de_configuracion(): void
    {
        $usuario = $this->usuario('administrador');

        // El sidebar ya NO enlaza Correo directamente: enlaza el centro.
        $sidebar = $this->sidebarDe(
            $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent()
        );
        $this->assertStringNotContainsString(route('configuracion.correo.edit', [], false), $sidebar);
        $this->assertStringContainsString(route('configuracion.resumen', [], false), $sidebar);

        // Y desde el centro, en un clic.
        $this->actingAs($usuario)->get(route('configuracion.resumen'))->assertOk()
            ->assertSee(route('configuracion.correo.edit'), false)
            ->assertSee('Correo');
    }

    /** Quien no es administrador no ve ninguna puerta a configuración. */
    public function test_configuracion_no_aparece_para_los_demas_roles(): void
    {
        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $sidebar = $this->sidebarDe(
                $this->actingAs($this->usuario($rol))->get(route('dashboard'))->assertOk()->getContent()
            );

            $this->assertStringNotContainsString(route('configuracion.correo.edit', [], false), $sidebar, "El rol {$rol} no debe ver Configuración > Correo.");
            // La entrada nueva tampoco: sustituir seis filas por una no puede
            // abrirle la puerta a nadie que antes no la tuviera.
            $this->assertStringNotContainsString(route('configuracion.resumen', [], false), $sidebar, "El rol {$rol} no debe ver el Centro de Configuración.");
        }

        // Y las URL siguen cerradas en backend, que es el candado de verdad.
        $this->actingAs($this->usuario('facturacion'))->get(route('configuracion.correo.edit'))->assertForbidden();
        $this->actingAs($this->usuario('facturacion'))->get(route('configuracion.resumen'))->assertForbidden();
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
            // Exportaciones ya no aporta tres filas: aporta UNA, la de listas de empaque,
            // dentro de Ventas y facturación. El catálogo de productos de exportación se
            // alcanza desde «Productos» (es su segunda pestaña) y los clientes de
            // exportación desde la ficha del cliente, así que ninguno necesita fila.
            'facturacion.listas.index',
        ];

        return [
            // Administrador. Tres cambios declarados respecto del inventario anterior,
            // y ninguno le abre una pantalla que antes no tuviera:
            //
            //  - SALE 'facturacion.preparar-produccion': el checklist deja el menú
            //    cotidiano. Ruta y permiso siguen; se llega por URL (ver
            //    test_preparar_emision_real_sigue_accesible_por_url_para_los_roles_autorizados).
            //  - SALEN las seis filas sueltas de configuración y ENTRA
            //    'configuracion.resumen': una puerta al Centro de Configuración en vez
            //    de seis atajos que además escondían las otras ocho pantallas (ver
            //    test_las_catorce_pantallas_de_configuracion_son_alcanzables_desde_su_centro).
            //  - ENTRA 'rutas.dashboard': el selector de área ahora vive TAMBIÉN dentro
            //    del panel lateral, para poder cambiar de área en móvil. Es la MISMA
            //    lista que ya ofrecía el desplegable superior (AreaSistema::visiblesPara),
            //    que el administrador siempre tuvo.
            'administrador' => ['administrador', [
                ...$lecturaOperativa,
                'rutas.dashboard',
                'usuarios.index', 'auditoria.index', 'importaciones.index',
                'configuracion.resumen',
                'admin.salud-sistema',
            ]],
            // Jefatura: lectura amplia, sin administración. Una sola área visible
            // (dte.ver), así que el selector del panel no se dibuja: sin cambios.
            'jefatura' => ['jefatura', $lecturaOperativa],
            // Facturación: pierde el checklist de emisión real y no gana nada.
            'facturacion' => ['facturacion', $lecturaOperativa],
            // Contabilidad: lo mismo + auditoría (auditoria.ver). Sin cambios.
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

    /**
     * El área Cobros presenta salidas, bandeja, rutas, la custodia del papel y —visualmente— PPQ.
     *
     * Los tres enlaces de custodia (excepciones, recepción y personal) van con su propio
     * permiso: quien solo mira documentos no los ve. Acá se comprueba con administrador, que
     * los tiene todos; los roles sin permiso están cubiertos por las pruebas de autorización.
     */
    public function test_el_area_cobros_presenta_sus_enlaces_mas_prontos_pagos(): void
    {
        $html = $this->actingAs($this->usuario('administrador'))->get(route('rutas.dashboard'))->assertOk()->getContent();

        $this->assertSame(
            $this->urls([
                // 'dashboard' es el aterrizaje del área Facturación: lo aporta el
                // selector de áreas del panel, que es lo que permite volver desde un
                // teléfono. No es una pantalla nueva para este rol.
                'dashboard',
                'rutas.dashboard', 'rutas.salidas.index', 'rutas.documentos.index', 'rutas.rutas.index',
                // Custodia del CCF físico: la bandeja de lo que no cuadra, la pantalla de
                // quien recibe en oficina y el catálogo de quienes salen a ruta.
                'rutas.excepciones.index', 'rutas.recepcion.index', 'rutas.personal.index',
                'ppq.index', 'ppq.lotes.index',
            ]),
            $this->enlacesDelSidebar($html),
        );

        $sidebar = $this->sidebarDe($html);
        $this->assertStringContainsString('Resumen', $sidebar);
        $this->assertStringContainsString('Documentos por cobrar', $sidebar);
        $this->assertStringContainsString('Pronto pago', $sidebar);
        $this->assertStringContainsString('Recepción de CCF', $sidebar);
        $this->assertStringContainsString('Personal operativo', $sidebar);
        $this->assertStringContainsString('Excepciones', $sidebar);
    }

    public function test_jefatura_ve_secciones_operativas_de_lectura_pero_no_administracion(): void
    {
        // Jefatura tiene lectura amplia (ve PPQ, Contabilidad y Exportaciones), pero
        // NO Administración, Configuración ni Sistema.
        $resp = $this->actingAs($this->usuario('jefatura'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Pronto pago');
        $resp->assertSee('Contabilidad');
        // Exportaciones ya no es una sección; su parte visible es la fila de listas.
        $resp->assertSee('Listas de empaque');
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
            'clientes.index', 'productos.index', 'facturacion.index',
            'ppq.index', 'ppq.lotes.index', 'documentos-recibidos.index', 'facturacion.reporte-contadora',
            'contabilidad.paquete', 'facturacion.listas.index',
            'usuarios.index', 'auditoria.index', 'importaciones.index',
            'configuracion.resumen', 'admin.salud-sistema',
        ] as $ruta) {
            $resp->assertSee(route($ruta), false);
        }
    }

    public function test_ruta_activa_se_marca_con_aria_current(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.listas.index'))
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
            'ventas / listas de empaque' => ['facturacion.listas.index', 'ventas'],
            'ventas / productos de exportación' => ['productos.exportacion.index', 'ventas'],
            'administracion / usuarios' => ['usuarios.index', 'administracion'],
            // «configuracion» ya no figura: con una sola fila el grupo dejó de ser
            // colapsable (x-sidebar-group sin `clave`), igual que Inicio y Sistema.
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

    /**
     * Los grupos que NO contienen la ruta actual nacen colapsados, y además con el
     * panel ya oculto DESDE EL SERVIDOR: si se dibujaran abiertos y Alpine los
     * cerrara al arrancar, cada carga empezaría con un parpadeo.
     *
     * Sin JavaScript nadie los abriría, así que el layout trae un <noscript> que
     * los vuelve a mostrar: sin JS se pierde el colapso, nunca los enlaces.
     */
    public function test_los_grupos_inactivos_nacen_colapsados_sin_esconder_enlaces_sin_js(): void
    {
        $html = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent();
        $sidebar = $this->sidebarDe($html);

        // En el dashboard ningún grupo colapsable contiene la ruta activa.
        $this->assertStringContainsString('data-sidebar-panel', $sidebar);
        $this->assertStringContainsString('style="display: none"', $sidebar);

        // La red de seguridad sin JavaScript vive en el layout, no en el sidebar.
        $this->assertStringContainsString('[data-sidebar-panel]{display:block !important;}', $html);
        $this->assertStringContainsString('<noscript>', $html);
    }

    /**
     * El bloque de PPQ en la barra de Facturación se llama «Pronto pago» y cuelga
     * sus dos opciones directamente, sin subtítulo intermedio.
     *
     * Antes el grupo se llamaba «Cobros» y dentro llevaba un subtítulo «Prontos
     * Pagos»: el rótulo de fuera prometía todo el ciclo de cobro cuando acá sólo
     * está el pronto pago, y el de dentro repetía la misma idea un escalón más
     * abajo. Con dos opciones, ese escalón no agrupaba nada.
     */
    public function test_pronto_pago_es_un_grupo_plano_en_la_barra_de_facturacion(): void
    {
        // Sobre las SECCIONES, no sobre el panel entero: «Cobros» sigue —y debe
        // seguir— apareciendo como nombre del área en el selector de arriba, que es
        // justo la distinción que este renombrado hace visible.
        $secciones = $this->seccionesDelSidebar(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        $this->assertStringContainsString('Pronto pago', $secciones);

        // Ni el rótulo viejo del grupo ni el subtítulo que llevaba dentro.
        $this->assertStringNotContainsString('Cobros', $secciones);
        $this->assertStringNotContainsString('Prontos Pagos', $secciones);

        // Y las dos opciones siguen ahí, en orden.
        $this->assertMatchesRegularExpression(
            '/Pronto pago.*Buscar CCF \/ NC.*Historial PPQ/su',
            $secciones,
            'Bajo «Pronto pago» deben colgar Buscar CCF / NC e Historial PPQ, en ese orden.',
        );
    }

    /**
     * El ÁREA sigue llamándose «Cobros» —es otra cosa, con más contenido— pero el
     * módulo de PPQ se llama «Pronto pago» también aquí.
     *
     * En la primera versión de este cambio sólo se renombró la barra de Facturación
     * y esta prueba fijaba lo contrario: que la barra del área conservara «Prontos
     * Pagos». Era un nombre distinto para el mismo módulo según por dónde entraras,
     * y eso obliga al usuario a deducir que hablan de lo mismo. El singular es ahora
     * el único rótulo visible; los nombres técnicos (permiso ppq.ver, prefijo /ppq,
     * rutas ppq.*) no cambiaron.
     */
    public function test_el_area_cobros_conserva_su_nombre_y_usa_el_mismo_rotulo_del_modulo(): void
    {
        $html = $this->actingAs($this->usuario('administrador'))->get(route('rutas.dashboard'))->assertOk()->getContent();
        $sidebar = $this->sidebarDe($html);

        // El área conserva su nombre.
        $this->assertStringContainsString('Cobros', $sidebar);
        // Y el módulo se llama igual que en la barra de Facturación.
        $this->assertStringContainsString('Pronto pago', $sidebar);
        $this->assertStringNotContainsString('Prontos Pagos', $sidebar);
    }

    // ------------------------------------------------------------------ preparación

    /**
     * «Preparar emisión real» sale del MENÚ, no del sistema. Retirar una opción de
     * la navegación y borrar funcionalidad son cosas distintas, y esta prueba fija
     * la diferencia: la pantalla sigue respondiendo por URL a quien tiene
     * `preparacion.ver`, y sigue cerrada para quien no.
     */
    public function test_preparar_emision_real_sigue_accesible_por_url_para_los_roles_autorizados(): void
    {
        foreach (['administrador', 'facturacion'] as $rol) {
            $usuario = $this->usuario($rol);

            // Fuera del menú...
            $sidebar = $this->sidebarDe(
                $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent()
            );
            $this->assertStringNotContainsString(
                route('facturacion.preparar-produccion', [], false),
                $sidebar,
                "El rol {$rol} no debería ver «Preparar emisión real» en el sidebar.",
            );

            // ...pero la pantalla sigue ahí.
            $this->actingAs($usuario)
                ->get(route('facturacion.preparar-produccion'))
                ->assertOk()
                ->assertSee('Preparar emisión real');
        }

        // Y el candado no se aflojó: quien no tiene preparacion.ver sigue fuera.
        foreach (['jefatura', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturacion.preparar-produccion'))
                ->assertForbidden();
        }
    }

    // ------------------------------------------------------------------ configuración

    /**
     * Las CATORCE pantallas del Centro de Configuración son alcanzables desde su
     * portada, que es la única fila de configuración que queda en el sidebar.
     *
     * Es la prueba que justifica haber cambiado seis atajos por una puerta: antes
     * ocho de estas pantallas no tenían ninguna entrada y solo se llegaba a ellas
     * entrando primero a otra pantalla de configuración y descubriendo el índice
     * de al lado.
     *
     * @return array<string, array{0: string}>
     */
    public static function pantallasDeConfiguracion(): array
    {
        return [
            'resumen' => ['configuracion.resumen'],
            'empresa emisora' => ['configuracion.empresa.edit'],
            'establecimientos' => ['configuracion.establecimientos.index'],
            'puntos de venta' => ['configuracion.puntos-venta.index'],
            'hacienda / api' => ['configuracion.fiscal.hacienda'],
            'certificado y firmador' => ['configuracion.fiscal.firmador'],
            'correlativos' => ['configuracion.correlativos.index'],
            'parámetros fiscales' => ['configuracion.fiscal.parametros'],
            'invalidación' => ['configuracion.fiscal.invalidacion'],
            'correo y servidor' => ['configuracion.correo.edit'],
            'contabilidad' => ['configuracion.contabilidad.edit'],
            'gmail / prontos pagos' => ['configuracion.integraciones.gmail'],
            'buzón de compras' => ['configuracion.integraciones.documentos-recibidos'],
            'respaldos y estado' => ['configuracion.sistema'],
        ];
    }

    #[DataProvider('pantallasDeConfiguracion')]
    public function test_las_catorce_pantallas_de_configuracion_son_alcanzables_desde_su_centro(string $ruta): void
    {
        $usuario = $this->usuario('administrador');

        // Enlazada desde la portada del centro...
        $this->actingAs($usuario)->get(route('configuracion.resumen'))->assertOk()
            ->assertSee(route($ruta), false);

        // ...y la pantalla responde de verdad (no es un enlace a una página muerta).
        $this->actingAs($usuario)->get(route($ruta))->assertOk();
    }

    /** Y el sidebar llega al centro en un solo clic. */
    public function test_el_sidebar_lleva_al_centro_de_configuracion_con_una_sola_fila(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        $this->assertStringContainsString(route('configuracion.resumen', [], false), $sidebar);

        // Las seis filas sueltas de antes ya no están: eran atajos que escondían el
        // resto del módulo.
        foreach ([
            'configuracion.empresa.edit', 'configuracion.establecimientos.index',
            'configuracion.puntos-venta.index', 'configuracion.correlativos.index',
            'configuracion.contabilidad.edit', 'configuracion.correo.edit',
        ] as $ruta) {
            $this->assertStringNotContainsString(route($ruta, [], false), $sidebar);
        }
    }

    // ------------------------------------------------------------------ invalidaciones

    /**
     * «Invalidaciones» gana una puerta —antes no tenía ninguna— pero no una fila
     * permanente: es una acción ocasional del propio listado de documentos.
     */
    public function test_invalidaciones_es_una_accion_del_listado_y_no_una_fila_del_sidebar(): void
    {
        $admin = $this->usuario('administrador');

        $html = $this->actingAs($admin)->get(route('facturacion.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('facturacion.invalidaciones', [], false), $html);
        $this->assertStringNotContainsString(
            route('facturacion.invalidaciones', [], false),
            $this->sidebarDe($html),
            'Invalidaciones no debe ocupar una fila del sidebar.',
        );

        // Quien no puede invalidar no recibe la puerta: hoy solo el administrador
        // tiene dte.invalidar. Ocultar no autoriza — el candado sigue en DtePolicy.
        foreach (['facturacion', 'jefatura', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))->get(route('facturacion.index'))->assertOk()
                ->assertDontSee(route('facturacion.invalidaciones'), false);
        }
    }

    // ------------------------------------------------------------------ áreas de trabajo

    /**
     * El cambio de área funciona en CUALQUIER ancho. Antes el único selector era
     * `hidden sm:block` en la barra superior, así que por debajo de 640 px no había
     * forma de llegar a Producción, Cobros o Asistencia salvo escribir la URL.
     *
     * Ahora el panel lateral —que ES la navegación por debajo de lg— lleva la lista
     * dentro, y el desplegable superior se reserva para lg. Cada ancho tiene
     * exactamente un selector y ninguno depende del otro.
     */
    public function test_el_area_se_puede_cambiar_desde_el_panel_lateral(): void
    {
        config()->set('planta.enabled', true);

        $html = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent();
        $sidebar = $this->sidebarDe($html);

        // La lista de áreas vive DENTRO del panel, con nombre accesible propio.
        $this->assertStringContainsString('aria-label="Áreas de trabajo"', $sidebar);
        foreach (['dashboard', 'planta.dashboard', 'rutas.dashboard'] as $aterrizaje) {
            $this->assertStringContainsString(
                route($aterrizaje, [], false),
                $sidebar,
                "El panel lateral debería ofrecer el área que aterriza en {$aterrizaje}.",
            );
        }

        // Y no depende de que un elemento aparezca a partir de sm.
        $this->assertStringNotContainsString('hidden sm:block', $html);
    }

    /** El desplegable de escritorio sigue existiendo, ahora reservado a lg. */
    public function test_el_selector_de_areas_de_escritorio_se_reserva_a_pantalla_grande(): void
    {
        $html = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-area-selector', $html);
        $this->assertStringContainsString('<div class="hidden lg:block" data-area-selector>', $html);
    }

    /**
     * AISLAMIENTO. El selector solo muestra áreas permitidas Y habilitadas, así que
     * producción —que solo tiene planta.ver— no recibe ni el selector ni un solo
     * enlace fiscal. Es la garantía que no se puede perder al tocar navegación.
     */
    public function test_produccion_no_recibe_selector_de_areas_ni_enlaces_fiscales(): void
    {
        config()->set('planta.enabled', true);

        $html = $this->actingAs($this->usuario('produccion'))->get(route('planta.dashboard'))->assertOk()->getContent();
        $sidebar = $this->sidebarDe($html);

        // Con una sola área visible no se dibuja ningún selector, ni arriba ni dentro.
        $this->assertStringNotContainsString('aria-label="Áreas de trabajo"', $sidebar);
        $this->assertStringNotContainsString('data-area-selector', $html);

        // Comparación de URL COMPLETAS y no de subcadenas: «/productos» aparece
        // dentro de «/planta/productos-base», que sí es suyo, y una comprobación por
        // subcadena daría un falso positivo justo en la prueba que más precisión pide.
        $enlaces = $this->enlacesDelSidebar($html);

        foreach ([
            'dashboard', 'facturacion.index', 'clientes.index', 'productos.index',
            'ppq.index', 'rutas.dashboard', 'configuracion.resumen', 'admin.salud-sistema',
        ] as $ruta) {
            $this->assertNotContains(
                route($ruta),
                $enlaces,
                "Producción no debe recibir el enlace {$ruta}.",
            );
        }
    }

    /**
     * Un módulo apagado no se ofrece aunque el usuario tenga el permiso: Asistencia
     * viene apagada por defecto y el administrador tiene todos los permisos.
     */
    public function test_el_panel_no_ofrece_areas_de_modulos_apagados(): void
    {
        config()->set('planta.enabled', false);
        config()->set('asistencia.enabled', false);

        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        $this->assertStringNotContainsString(route('planta.dashboard', [], false), $sidebar);
        $this->assertStringNotContainsString(route('asistencia.dashboard', [], false), $sidebar);
    }

    // ------------------------------------------------------------------ accesibilidad

    /**
     * El panel móvil se puede operar con el teclado y anunciarse a un lector de
     * pantalla: la hamburguesa tiene nombre propio (el icono cambia de dibujo, no
     * de significado), dice qué panel controla y en qué estado está, y Escape lo
     * cierra devolviendo el foco al botón que lo abrió.
     */
    public function test_el_panel_lateral_es_operable_con_teclado_y_lector_de_pantalla(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('aria-label="Menú de navegación"', false);
        $resp->assertSee('aria-controls="sidebar-principal"', false);
        $resp->assertSee(':aria-expanded="sidebarAbierta ? \'true\' : \'false\'"', false);
        $resp->assertSee('id="sidebar-principal"', false);
        $resp->assertSee('aria-label="Navegación principal"', false);

        // Escape cierra y devuelve el foco a la hamburguesa.
        $resp->assertSee('@keydown.escape.window', false);
        $resp->assertSee('$refs.hamburguesa?.focus()', false);

        // El foco se ve al llegar por teclado.
        $resp->assertSee('focus-visible:outline', false);
    }

    /** Nada del panel puede desbordar a lo ancho: el panel recorta en horizontal. */
    public function test_el_panel_lateral_no_desborda_en_horizontal(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        $this->assertStringContainsString('overflow-x-hidden', $sidebar);
        $this->assertStringContainsString('truncate', $sidebar);
    }

    /** Modo oscuro: cada superficie del panel declara su par claro/oscuro. */
    public function test_el_panel_lateral_tiene_modo_oscuro(): void
    {
        $sidebar = $this->sidebarDe(
            $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent()
        );

        foreach (['dark:bg-ink-900', 'dark:border-ink-600', 'dark:text-paper-300'] as $clase) {
            $this->assertStringContainsString($clase, $sidebar, "Falta el par oscuro «{$clase}» en el panel.");
        }
    }
}
