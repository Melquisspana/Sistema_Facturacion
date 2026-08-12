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
        $resp->assertSee('Rutas / Cobros');
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
     * D5 — la sidebar de Facturación no contiene el enlace al área de Planta.
     *
     * La aserción se acota al elemento <aside> a propósito: para el administrador
     * el enlace SÍ existe en la página, pero dentro del selector superior, que es
     * su único lugar legítimo. Mirar la página entera confundiría ambas cosas.
     */
    public function test_d5_la_sidebar_de_facturacion_no_contiene_el_enlace_a_planta(): void
    {
        $this->encenderModulo();

        foreach (['administrador', 'jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $sidebar = $this->sidebarDe(
                $this->actingAs($this->usuario($rol))->get(route('facturacion.index'))->assertOk()->getContent()
            );

            $this->assertStringNotContainsString(route('planta.dashboard'), $sidebar, "La sidebar del rol {$rol} no debe enlazar al área de Producción.");
            $this->assertStringNotContainsString('Producción', $sidebar);
            // Y sigue mostrando lo suyo.
            $this->assertStringContainsString(route('facturacion.index'), $sidebar);
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
