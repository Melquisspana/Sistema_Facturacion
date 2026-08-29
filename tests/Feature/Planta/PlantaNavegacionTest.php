<?php

namespace Tests\Feature\Planta;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selector superior de áreas y sidebar por área. Todo esto es PRESENTACIÓN: que
 * un enlace se dibuje o no nunca sustituye al candado de backend (probado en
 * PlantaAccesoTest). Lo que se verifica aquí es que no se le muestre a nadie una
 * puerta que no le corresponde, y que el área de Facturación no vea ni rastro del
 * módulo nuevo mientras esté apagado.
 */
class PlantaNavegacionTest extends TestCase
{
    use RefreshDatabase;

    /** Marcador del componente <x-area-selector>. */
    private const MARCADOR_SELECTOR = 'data-area-selector';

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function encenderModulo(): void
    {
        config()->set('planta.enabled', true);
    }

    /**
     * Cada prueba fija el estado del interruptor que necesita en vez de heredarlo
     * del ambiente. Así la suite da el mismo resultado con PLANTA_ENABLED=false
     * (el valor fijado en phpunit.xml) y con el flag forzado a true por env.
     */
    private function apagarModulo(): void
    {
        config()->set('planta.enabled', false);
    }

    // ---------------------------------------------------------------------
    // D1-D3: quién ve el selector.
    // ---------------------------------------------------------------------

    /** D1 — el administrador ve las dos áreas cuando el módulo está encendido. */
    public function test_d1_el_administrador_ve_las_dos_areas(): void
    {
        $this->encenderModulo();

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk();

        $resp->assertSee(self::MARCADOR_SELECTOR, false);
        $resp->assertSee('Facturación');
        $resp->assertSee('Producción');
        $resp->assertSee(route('planta.dashboard'), false);
    }

    /** D2 — el rol de producción tiene una sola área: no se le dibuja selector. */
    public function test_d2_produccion_no_ve_selector_por_tener_una_sola_area(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertDontSee(self::MARCADOR_SELECTOR, false);
    }

    /** D3 — los roles del área Facturación no ven selector, con el módulo encendido. */
    public function test_d3_los_roles_de_facturacion_no_ven_selector_con_el_modulo_encendido(): void
    {
        $this->encenderModulo();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee(self::MARCADOR_SELECTOR, false);
        }
    }

    /**
     * A3 / D3 bis — con el módulo apagado, PLANTA no se ve por ningún lado.
     *
     * El administrador quedó fuera de este bucle al aparecer el área Rutas / Cobros:
     * ahora tiene dos áreas visibles (Facturación y Rutas) aun con Planta apagada, y
     * el selector se dibuja a partir de dos. Lo que sigue importando —que no haya ni
     * rastro de Planta— se comprueba aparte, en {@see test_a3_bis_el_administrador_no_ve_planta_apagada()}.
     */
    public function test_a3_con_el_modulo_apagado_no_hay_selector_ni_enlace(): void
    {
        $this->apagarModulo();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $resp = $this->actingAs($this->usuario($rol))
                ->get(route('dashboard'))
                ->assertOk();

            $resp->assertDontSee(self::MARCADOR_SELECTOR, false);
            $resp->assertDontSee(route('planta.dashboard'), false);
        }
    }

    /**
     * A3 bis — el administrador SÍ ve selector con Planta apagada (por el área Rutas /
     * Cobros), pero Planta no aparece en él. El selector dibuja las áreas habilitadas,
     * y una apagada no lo está.
     */
    public function test_a3_bis_el_administrador_no_ve_planta_apagada(): void
    {
        $this->apagarModulo();

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk();

        $resp->assertSee(self::MARCADOR_SELECTOR, false);
        // Por el ENLACE de aterrizaje y no por la palabra: «Cobros» es el nombre del
        // ÁREA, y desde que la barra de Facturación dejó de llamar «Cobros» a su
        // bloque de PPQ, comprobar la palabra suelta ya no distingue una cosa de la
        // otra. Lo que esta prueba quiere saber es que el área sigue ofrecida.
        $resp->assertSee(route('rutas.dashboard'), false);
        // Se comprueba por el ENLACE al área y no por la palabra «Producción»: en el
        // dashboard de Facturación esa palabra ya aparece como ambiente fiscal del DTE
        // («Ambiente: Producción»), que no tiene nada que ver con el área de planta.
        $resp->assertDontSee(route('planta.dashboard'), false);
    }

    // ---------------------------------------------------------------------
    // D4-D5: cada sidebar muestra SOLO lo de su área.
    // ---------------------------------------------------------------------

    /** D4 — la sidebar de Planta no filtra ningún enlace del área Facturación. */
    public function test_d4_la_sidebar_de_planta_no_contiene_enlaces_de_facturacion(): void
    {
        $this->encenderModulo();

        $resp = $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk();

        foreach ([
            'facturacion.index', 'clientes.index', 'productos.index', 'ppq.index',
            'exportaciones.index', 'documentos-recibidos.index', 'usuarios.index',
            'contabilidad.paquete', 'admin.salud-sistema', 'auditoria.index',
        ] as $ruta) {
            $resp->assertDontSee(route($ruta), false);
        }

        // Sí muestra lo suyo.
        $resp->assertSee(route('planta.dashboard'), false);
        $resp->assertSee('Producción');
    }

    /**
     * D4 bis — ni siquiera el administrador, que tiene TODOS los permisos, arrastra
     * la sidebar de Facturación al entrar al área de Producción: la sidebar la
     * elige el área activa (derivada de la URL), no los permisos del usuario.
     */
    public function test_d4_bis_el_administrador_en_planta_ve_la_sidebar_de_planta(): void
    {
        $this->encenderModulo();

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('planta.dashboard'))
            ->assertOk();

        $resp->assertDontSee(route('facturacion.index'), false);
        $resp->assertDontSee(route('usuarios.index'), false);
        $resp->assertSee(route('planta.dashboard'), false);
    }

    /**
     * D5 — la sidebar de Facturación no lista pantallas del área de Planta.
     *
     * QUÉ CAMBIÓ Y POR QUÉ. Antes esta prueba miraba el <aside> entero y exigía que
     * no apareciera ni una vez el enlace al área de Producción, con el argumento de
     * que el selector superior era «su único lugar legítimo». Ese selector se
     * dibujaba sólo desde sm, así que por debajo de 640 px no había NINGUNA forma de
     * cambiar de área: el administrador con un teléfono quedaba encerrado en
     * Facturación salvo que escribiera la URL. El cambio de área pasó a vivir
     * también DENTRO del panel, que es la navegación en esos anchos.
     *
     * Lo que la prueba protege sigue intacto, y es lo importante: la LISTA DE
     * SECCIONES de Facturación no puede ofrecer pantallas de Planta. Un enlace al
     * aterrizaje de otra área dentro de un selector de áreas rotulado no es
     * contaminación entre áreas; un «Existencias» colado entre «Clientes» y
     * «Productos» sí lo sería. Por eso se acota al panel MENOS el selector.
     *
     * El selector, además, sólo ofrece áreas que el usuario ya puede ver
     * (AreaSistema::visiblesPara: módulo encendido Y permiso de entrada), así que no
     * abre ninguna puerta nueva — lo comprueba
     * test_el_selector_de_areas_no_ofrece_pantallas_de_planta.
     */
    public function test_d5_la_sidebar_de_facturacion_no_lista_pantallas_de_planta(): void
    {
        $this->encenderModulo();

        foreach (['administrador', 'jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $secciones = $this->seccionesDelSidebar(
                $this->actingAs($this->usuario($rol))->get(route('facturacion.index'))->assertOk()->getContent()
            );

            $this->assertStringNotContainsString(route('planta.dashboard'), $secciones, "La sidebar del rol {$rol} no debe enlazar al área de Producción.");
            $this->assertStringNotContainsString('Producción', $secciones);
            // Y sigue mostrando lo suyo.
            $this->assertStringContainsString(route('facturacion.index'), $secciones);
        }
    }

    /**
     * El selector de áreas del panel ofrece ATERRIZAJES de área, nunca pantallas
     * internas de otra área. Es la contraparte de D5: sin ella, «acotar la prueba al
     * panel menos el selector» sería un agujero por el que podría colarse cualquier
     * cosa con sólo meterla dentro del selector.
     */
    public function test_el_selector_de_areas_no_ofrece_pantallas_de_planta(): void
    {
        $this->encenderModulo();

        $selector = $this->selectorDeAreasDelSidebar(
            $this->actingAs($this->usuario('administrador'))->get(route('facturacion.index'))->assertOk()->getContent()
        );

        $this->assertNotSame('', $selector, 'El administrador ve más de un área: el selector debe dibujarse.');
        $this->assertStringContainsString(route('planta.dashboard'), $selector);

        foreach ([
            'planta.existencias.index', 'planta.recepciones.index', 'planta.traslados.index',
            'planta.ajustes.index', 'planta.movimientos.index', 'planta.insumos.index',
        ] as $ruta) {
            $this->assertStringNotContainsString(
                route($ruta),
                $selector,
                "El selector de áreas no debe ofrecer la pantalla {$ruta}.",
            );
        }
    }

    /** Un rol de una sola área no ve selector: no hay nada entre lo que elegir. */
    public function test_un_rol_de_una_sola_area_no_ve_el_selector(): void
    {
        $this->encenderModulo();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $selector = $this->selectorDeAreasDelSidebar(
                $this->actingAs($this->usuario($rol))->get(route('facturacion.index'))->assertOk()->getContent()
            );

            $this->assertSame('', $selector, "El rol {$rol} sólo ve un área: no debe dibujarse el selector.");
        }
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

    /** El selector de áreas del panel, o cadena vacía si no se dibuja. */
    private function selectorDeAreasDelSidebar(string $html): string
    {
        $sidebar = $this->sidebarDe($html);
        $inicio = strpos($sidebar, '<nav aria-label="Áreas de trabajo"');

        if ($inicio === false) {
            return '';
        }

        $fin = strpos($sidebar, '</nav>', $inicio);
        $this->assertNotFalse($fin, 'El selector de áreas no está cerrado.');

        return substr($sidebar, $inicio, $fin - $inicio + 6);
    }

    /**
     * La sidebar SIN el selector de áreas: la lista de secciones del área activa,
     * que es de lo que habla D5. Elegir un área es una operación distinta de
     * navegar dentro de una, y mezclarlas en la misma aserción hacía imposible
     * ofrecer el cambio de área en el panel (única forma de cambiarla en móvil).
     */
    private function seccionesDelSidebar(string $html): string
    {
        $sidebar = $this->sidebarDe($html);
        $selector = $this->selectorDeAreasDelSidebar($html);

        return $selector === '' ? $sidebar : str_replace($selector, '', $sidebar);
    }

    /** El sidebar de Planta marca su enlace activo igual que el resto del sistema. */
    public function test_la_ruta_activa_se_marca_en_la_sidebar_de_planta(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }
}
