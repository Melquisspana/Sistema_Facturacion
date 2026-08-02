<?php

namespace Tests\Feature\Planta;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceso al área Producción/Planta: interruptor del módulo (PLANTA_ENABLED) y
 * autorización por permiso. Todo se comprueba en BACKEND, escribiendo la URL
 * directamente: el selector superior y la sidebar no participan.
 *
 * La suite arranca con el módulo APAGADO (phpunit.xml), igual que producción.
 */
class PlantaAccesoTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['administrador', 'jefatura', 'facturacion', 'contabilidad', 'produccion'];

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
    // A. Módulo APAGADO (estado por defecto).
    // ---------------------------------------------------------------------

    /** A1 */
    public function test_a1_con_flag_apagado_el_administrador_recibe_404(): void
    {
        $this->apagarModulo();

        $this->actingAs($this->usuario('administrador'))
            ->get('/planta')
            ->assertNotFound();
    }

    /** A2 */
    public function test_a2_con_flag_apagado_ningun_rol_entra_por_url(): void
    {
        $this->apagarModulo();

        foreach (self::ROLES as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get('/planta')
                ->assertNotFound("El rol {$rol} no debería entrar con el módulo apagado.");
        }
    }

    /**
     * A0 — cableado: la suite debe fijar el módulo APAGADO en phpunit.xml, para que
     * toda la batería histórica corra en el mismo estado que el servidor real. Se
     * comprueba sobre el archivo y no sobre config(), porque una variable de
     * entorno puede sobrescribir el valor en una corrida controlada y eso no debe
     * invalidar el cableado.
     */
    public function test_a0_la_suite_fija_el_modulo_apagado_en_phpunit_xml(): void
    {
        $this->assertStringContainsString(
            '<env name="PLANTA_ENABLED" value="false"/>',
            (string) file_get_contents(base_path('phpunit.xml'))
        );
    }

    /** A4 — las rutas se registran siempre; el 404 lo da el middleware. */
    public function test_a4_la_ruta_resuelve_aunque_el_modulo_este_apagado(): void
    {
        $this->apagarModulo();

        $this->assertSame(url('/planta'), route('planta.dashboard'));
    }

    // ---------------------------------------------------------------------
    // B. Módulo ENCENDIDO: manda el permiso.
    // ---------------------------------------------------------------------

    /** B1 */
    public function test_b1_produccion_y_administrador_entran_con_el_modulo_encendido(): void
    {
        $this->encenderModulo();

        foreach (['produccion', 'administrador'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get('/planta')
                ->assertOk();
        }
    }

    /** B2 — URL escrita a mano por roles del área Facturación. */
    public function test_b2_los_roles_de_facturacion_reciben_403_aunque_escriban_la_url(): void
    {
        $this->encenderModulo();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get('/planta')
                ->assertForbidden("El rol {$rol} no pertenece al área Producción.");
        }
    }

    /** B3 */
    public function test_b3_el_invitado_es_redirigido_al_login(): void
    {
        $this->encenderModulo();

        $this->get('/planta')->assertRedirect(route('login'));
    }

    /**
     * B3 bis — el invitado va al login TAMBIÉN con el módulo apagado: `auth` es el
     * primer middleware del grupo, antes que `modulo.planta`. Es el orden correcto
     * y el mismo del resto del sistema: a un anónimo no se le responde nada
     * distinto de "identifíquese", y el interruptor del módulo solo se evalúa para
     * usuarios ya autenticados (A1/A2).
     */
    public function test_b3_bis_el_invitado_va_al_login_con_el_modulo_apagado(): void
    {
        $this->apagarModulo();

        $this->get('/planta')->assertRedirect(route('login'));
    }

    /**
     * D6 — contenido de la pantalla: indicadores del ÁREA y ninguno fiscal.
     *
     * El dashboard dejó de ser una pantalla informativa al implementarse el panel
     * operativo, igual que los catálogos y los lotes dejaron de ser «pendientes».
     * Lo que NO cambió, y es la razón de fondo de esta prueba, es que quien
     * trabaja en planta no ve una sola cifra de facturación: el aislamiento del
     * área se sigue verificando aquí y en PlantaRedireccionTest C4.
     */
    public function test_d6_el_dashboard_muestra_indicadores_del_area_y_ninguno_fiscal(): void
    {
        $this->encenderModulo();

        $resp = $this->actingAs($this->usuario('produccion'))->get('/planta')->assertOk();

        $resp->assertSee('Producción');
        $resp->assertSee('Área de Producción');
        $resp->assertSee('Traslados en tránsito');

        // Los textos de la Fase 1 describían un módulo sin funciones. Ya no son
        // ciertos y no deben volver por copiar y pegar.
        foreach ([
            'Módulo en preparación',
            'Esta área todavía no tiene funciones operativas',
            'No hay datos que mostrar',
            'Qué se podrá hacer aquí más adelante',
        ] as $obsoleto) {
            $resp->assertDontSee($obsoleto);
        }

        // Ni una cifra ni un rótulo de indicador tomado del área fiscal.
        foreach (['DTE aceptados', 'Ventas del mes', 'Jobs fallidos', 'Estado técnico', 'Diagnóstico'] as $indicador) {
            $resp->assertDontSee($indicador);
        }
    }
}
