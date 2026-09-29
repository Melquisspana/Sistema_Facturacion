<?php

namespace Tests\Feature\Rutas;

use App\Enums\AreaSistema;
use App\Enums\EstadoSalidaRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceso al área Rutas. Todo se comprueba en BACKEND, escribiendo la URL
 * directamente: el selector superior y la sidebar no participan de la decisión.
 *
 * Administrador, facturación y jefatura tienen `rutas.ver` y `rutas.gestionar`
 * (decisión del usuario, 26/09/2026). Contabilidad y producción no entran.
 */
class RutasAccesoTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES_CON_ACCESO = ['administrador', 'facturacion', 'jefatura'];

    private const ROLES_SIN_ACCESO = ['contabilidad', 'produccion'];

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    public function test_administrador_facturacion_y_jefatura_entran_y_gestionan(): void
    {
        foreach (self::ROLES_CON_ACCESO as $rol) {
            $usuario = $this->usuario($rol);

            $this->actingAs($usuario)
                ->get(route('rutas.dashboard'))
                ->assertOk()
                ->assertSee('Configurar rutas');

            $this->actingAs($usuario)->get(route('rutas.salidas.create'))->assertOk();
            $this->actingAs($usuario)->get(route('rutas.asignacion.index'))->assertOk();
        }
    }

    /** Jefatura gestiona Rutas, pero nada fiscal: sigue sin emitir ni gestionar DTE. */
    public function test_jefatura_gestiona_rutas_sin_ganar_permisos_fiscales(): void
    {
        $jefatura = $this->usuario('jefatura');

        $this->assertTrue($jefatura->can('rutas.gestionar'));
        $this->assertFalse($jefatura->can('dte.emitir'));
        $this->assertFalse($jefatura->can('dte.gestionar'));
        $this->assertFalse($jefatura->can('ppq.gestionar'));
    }

    public function test_los_demas_roles_reciben_403_en_todas_las_pantallas(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);

        $urls = [
            route('rutas.dashboard'),
            route('rutas.rutas.index'),
            route('rutas.rutas.show', $ruta),
            route('rutas.salidas.index'),
            route('rutas.asignacion.index'),
        ];

        foreach (self::ROLES_SIN_ACCESO as $rol) {
            $usuario = $this->usuario($rol);

            foreach ($urls as $url) {
                $this->actingAs($usuario)->get($url)->assertForbidden();
            }
        }
    }

    public function test_un_invitado_va_al_login(): void
    {
        $this->get(route('rutas.dashboard'))->assertRedirect(route('login'));
    }

    /**
     * Ver no es gestionar: con `rutas.ver` prestado pero sin `rutas.gestionar`, las
     * pantallas de lectura abren y todo lo que escribe da 403.
     */
    public function test_ver_sin_gestionar_no_puede_escribir(): void
    {
        $usuario = $this->usuario('contabilidad')->givePermissionTo('rutas.ver');
        $ruta = Ruta::create(['nombre' => 'Santa Ana']);

        $this->actingAs($usuario)->get(route('rutas.rutas.index'))->assertOk();
        $this->actingAs($usuario)->get(route('rutas.rutas.show', $ruta))->assertOk();

        $this->actingAs($usuario)->get(route('rutas.rutas.create'))->assertForbidden();
        $this->actingAs($usuario)->post(route('rutas.rutas.store'), ['nombre' => 'X'])->assertForbidden();
        $this->actingAs($usuario)->patch(route('rutas.rutas.toggle-activa', $ruta))->assertForbidden();
        $this->actingAs($usuario)->post(route('rutas.rutas.salas.store', $ruta), ['sucursales' => [1]])->assertForbidden();
        $this->actingAs($usuario)->get(route('rutas.salidas.create'))->assertForbidden();
        $this->actingAs($usuario)->post(route('rutas.rutas.cobertura.store', $ruta), ['departamento_id' => 1])->assertForbidden();
        $this->actingAs($usuario)->post(route('rutas.asignacion.aplicar'), ['sucursales' => [1]])->assertForbidden();

        // Y nada se escribió por el intento.
        $this->assertSame(1, Ruta::count());
        $this->assertTrue($ruta->refresh()->activa);
    }

    /**
     * Todas las pantallas del área abren para el administrador. Es un smoke test
     * de compilación de vistas: un Blade roto acá no lo detecta ninguna otra prueba.
     */
    public function test_todas_las_pantallas_abren_para_el_administrador(): void
    {
        $admin = $this->usuario('administrador');
        $ruta = Ruta::create(['nombre' => 'San Miguel']);

        $salida = SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => '2026-08-14',
            'fecha_fin_estimada' => '2026-08-16',
            'estado' => EstadoSalidaRuta::Planificada,
            'created_by' => $admin->id,
        ]);

        $pantallas = [
            route('rutas.dashboard'),
            route('rutas.rutas.index'),
            route('rutas.rutas.create'),
            route('rutas.rutas.show', $ruta),
            // Con búsqueda activa se dibuja además el panel de resultados.
            route('rutas.rutas.show', [$ruta, 'q' => 'sala']),
            route('rutas.rutas.edit', $ruta),
            route('rutas.salidas.index'),
            route('rutas.salidas.create'),
            route('rutas.salidas.show', $salida),
            route('rutas.salidas.edit', $salida),
            route('rutas.asignacion.index'),
            route('rutas.asignacion.index', ['ruta_id' => $ruta->id]),
        ];

        foreach ($pantallas as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    // ------------------------------------------------------- área / navegación

    public function test_el_area_se_deriva_de_la_url_no_de_la_sesion(): void
    {
        $this->actingAs($this->usuario('administrador'));

        $this->get(route('rutas.dashboard'));
        $this->assertSame(AreaSistema::Rutas, AreaSistema::activaDesdeRequest());

        // Volver a Facturación no deja "pegada" el área anterior.
        $this->get(route('dashboard'));
        $this->assertNotSame(AreaSistema::Rutas, AreaSistema::activaDesdeRequest());
    }

    public function test_el_area_solo_es_visible_para_quien_tiene_el_permiso(): void
    {
        foreach (self::ROLES_CON_ACCESO as $rol) {
            $this->assertContains(AreaSistema::Rutas, AreaSistema::visiblesPara($this->usuario($rol)));
        }

        foreach (self::ROLES_SIN_ACCESO as $rol) {
            $this->assertNotContains(
                AreaSistema::Rutas,
                AreaSistema::visiblesPara($this->usuario($rol)),
                "El rol {$rol} no debería ver el área Rutas.",
            );
        }
    }

    /**
     * El área nueva no le quita nada a nadie: los roles existentes siguen entrando
     * donde entraban. Es la comprobación de que no rompimos la navegación previa.
     */
    public function test_las_areas_existentes_siguen_funcionando(): void
    {
        $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk();
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();
    }

    /** Facturación y jefatura siguen aterrizando en Facturación: Rutas es un área más. */
    public function test_facturacion_y_jefatura_siguen_aterrizando_en_facturacion(): void
    {
        foreach (['facturacion', 'jefatura'] as $rol) {
            $this->assertSame(AreaSistema::Facturacion, AreaSistema::principalPara($this->usuario($rol)));
        }
    }

    /** Los enlaces viejos de /rutas-cobros llevan al mismo lugar en /rutas. */
    public function test_la_url_vieja_redirige_a_la_nueva(): void
    {
        $this->get('/rutas-cobros')->assertRedirect('/rutas/');
        $this->get('/rutas-cobros/salidas/crear')->assertRedirect('/rutas/salidas/crear');
    }
}
