<?php

namespace Tests\Feature\Planta;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Aterrizaje por área en /dashboard (middleware `area.principal`).
 *
 * Cubre el caso que RolesPermisosTest ya no puede expresar: /dashboard no tiene
 * dos desenlaces sino cuatro (200 para el área Facturación, 302 al área propia,
 * 404 si el área propia está apagada, y el comportamiento histórico para un
 * usuario sin área). Verifica además que para el rol `produccion` el
 * DashboardController NI SIQUIERA SE INSTANCIA: no se ejecuta ninguna consulta
 * del panel fiscal.
 */
class PlantaRedireccionTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES_FACTURACION = ['administrador', 'jefatura', 'facturacion', 'contabilidad'];

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

    /**
     * Tablas que SOLO consulta el dashboard de Facturación. Si alguna aparece en
     * el log de consultas de una petición del rol `produccion`, el controlador
     * corrió y la corrección falló.
     *
     * @return array<int, string>
     */
    private function tablasDelDashboardFiscal(): array
    {
        return ['dtes', 'documentos_recibidos', 'exportaciones', 'failed_jobs', 'jobs'];
    }

    /**
     * Ejecuta la petición registrando todo el SQL y devuelve las sentencias.
     *
     * @return array<int, string>
     */
    private function consultasDe(callable $peticion): array
    {
        $consultas = [];
        DB::listen(static function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });

        $peticion();

        return $consultas;
    }

    // ---------------------------------------------------------------------
    // C1-C3, C6: rol produccion con el módulo ENCENDIDO -> /planta.
    // ---------------------------------------------------------------------

    /** C2 */
    public function test_c2_produccion_es_redirigido_del_dashboard_a_planta(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('dashboard'))
            ->assertRedirect(route('planta.dashboard'));
    }

    /** C1 — flujo real de login: Breeze redirige a /dashboard y de ahí se aterriza. */
    public function test_c1_tras_iniciar_sesion_produccion_aterriza_en_planta(): void
    {
        $this->encenderModulo();

        $usuario = $this->usuario('produccion');
        $usuario->forceFill(['password' => bcrypt('clave-de-prueba-123')])->save();

        $login = $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'clave-de-prueba-123',
        ]);

        $this->assertAuthenticatedAs($usuario);
        $login->assertRedirect(route('dashboard'));

        // Segundo salto: /dashboard -> /planta, y ahí termina en 200.
        $this->get(route('dashboard'))
            ->assertRedirect(route('planta.dashboard'));

        $this->get(route('planta.dashboard'))->assertOk();
    }

    /** C3 — la raíz del sitio termina en el área propia. */
    public function test_c3_la_raiz_lleva_a_produccion_hasta_su_area(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get('/')
            ->assertRedirect(route('dashboard'));

        $this->actingAs($this->usuario('produccion'))
            ->get(route('dashboard'))
            ->assertRedirect(route('planta.dashboard'));
    }

    /** C6 — siguiendo la cadena completa no hay bucle: termina en un único 200. */
    public function test_c6_la_cadena_de_redirecciones_termina_sin_bucle(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->followingRedirects()
            ->get('/')
            ->assertOk()
            // Ancla en la cabecera del panel operativo: el texto de la Fase 1
            // («Módulo en preparación») desapareció al implementarse el dashboard.
            ->assertSee('Área de Producción');
    }

    // ---------------------------------------------------------------------
    // C4: ninguna consulta del panel fiscal.
    // ---------------------------------------------------------------------

    /** C4 — módulo encendido: la redirección no toca ninguna tabla del dashboard. */
    public function test_c4_la_redireccion_no_ejecuta_consultas_del_dashboard_fiscal(): void
    {
        $this->encenderModulo();
        $usuario = $this->usuario('produccion');

        $consultas = $this->consultasDe(function () use ($usuario) {
            $this->actingAs($usuario)
                ->get(route('dashboard'))
                ->assertRedirect(route('planta.dashboard'));
        });

        foreach ($this->tablasDelDashboardFiscal() as $tabla) {
            foreach ($consultas as $sql) {
                $this->assertStringNotContainsString(
                    $tabla,
                    $sql,
                    "La petición no debería consultar «{$tabla}»: el DashboardController no debe instanciarse."
                );
            }
        }
    }

    /** C4 bis — módulo apagado: el 404 tampoco ejecuta consultas del panel. */
    public function test_c4_bis_el_404_tampoco_ejecuta_consultas_del_dashboard_fiscal(): void
    {
        $this->apagarModulo();
        $usuario = $this->usuario('produccion');

        $consultas = $this->consultasDe(function () use ($usuario) {
            $this->actingAs($usuario)
                ->get(route('dashboard'))
                ->assertNotFound();
        });

        foreach ($this->tablasDelDashboardFiscal() as $tabla) {
            foreach ($consultas as $sql) {
                $this->assertStringNotContainsString($tabla, $sql, "La petición no debería consultar «{$tabla}».");
            }
        }
    }

    // ---------------------------------------------------------------------
    // Módulo APAGADO: el rol produccion no entra al dashboard histórico.
    // ---------------------------------------------------------------------

    public function test_con_el_modulo_apagado_produccion_recibe_404_en_el_dashboard(): void
    {
        $this->apagarModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('dashboard'))
            ->assertNotFound();
    }

    public function test_con_el_modulo_apagado_produccion_recibe_404_tambien_en_planta(): void
    {
        $this->apagarModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.dashboard'))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // C5: los cuatro roles históricos, intactos con el flag en cualquier estado.
    // ---------------------------------------------------------------------

    /** C5 */
    public function test_c5_los_roles_de_facturacion_conservan_su_dashboard_con_el_modulo_apagado(): void
    {
        $this->apagarModulo();

        foreach (self::ROLES_FACTURACION as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('dashboard'))
                ->assertOk();
        }
    }

    /** C5 bis */
    public function test_c5_bis_los_roles_de_facturacion_conservan_su_dashboard_con_el_modulo_encendido(): void
    {
        $this->encenderModulo();

        foreach (self::ROLES_FACTURACION as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('dashboard'))
                ->assertOk();
        }
    }

    /**
     * Un usuario SIN rol conserva el comportamiento histórico (dashboard vacío,
     * no 404). La corrección del rol `produccion` no debía cambiarle nada: es el
     * caso de los usuarios recién creados por SSO antes de asignarles rol.
     */
    public function test_un_usuario_sin_rol_conserva_el_comportamiento_historico(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true]))
            ->get(route('dashboard'))
            ->assertOk();
    }
}
