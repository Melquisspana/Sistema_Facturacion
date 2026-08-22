<?php

namespace Tests\Feature\Asistencia;

use App\Enums\AreaSistema;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El ÁREA Asistencia: aterrizaje, navegación y apagado seguro.
 *
 * Lo que estas pruebas impiden es el fallo que dejó el área fuera de la Fase 1: un
 * área cuyo `rutaInicio()` no existe revienta la barra de navegación entera —con
 * `RouteNotFoundException`— para todo el que tenga su permiso, y el administrador
 * los tiene todos. Ahora la ruta existe; el test se queda para que siga
 * existiendo.
 *
 * Y la otra mitad: con `ASISTENCIA_ENABLED=false` ninguna pantalla debe quedar
 * utilizable NI aparecer. El apagado es de servidor y responde 404, no 403: para
 * quien toque la URL, el módulo sencillamente no está.
 */
class AreaAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Un usuario con SOLO el permiso de entrada al área. */
    private function soloVer(): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo(PermisoSistema::AsistenciaVer->value);
    }

    // ───────────────────────────── El enum del área ─────────────────────────────

    /**
     * La ruta de aterrizaje de TODAS las áreas tiene que existir. Es la comprobación
     * que faltaba: `route()` sobre un nombre inexistente no falla al definir el
     * enum, falla al pintar la barra de navegación de cualquier pantalla.
     */
    public function test_todas_las_areas_aterrizan_en_una_ruta_que_existe(): void
    {
        foreach (AreaSistema::cases() as $area) {
            $this->assertTrue(
                Route::has($area->rutaInicio()),
                "El área «{$area->value}» aterriza en «{$area->rutaInicio()}», que no existe. "
                .'La barra de navegación reventaría para quien tenga su permiso.'
            );
        }
    }

    public function test_el_area_se_apaga_con_el_interruptor_del_modulo(): void
    {
        config()->set('asistencia.enabled', false);
        $this->assertFalse(AreaSistema::Asistencia->habilitada());

        config()->set('asistencia.enabled', true);
        $this->assertTrue(AreaSistema::Asistencia->habilitada());
    }

    public function test_el_area_activa_se_deriva_de_la_url(): void
    {
        $this->actingAs($this->admin())->get(route('asistencia.dashboard'))->assertOk();

        // Las rutas del LECTOR se llaman api.asistencia.* y no pertenecen al área:
        // no tienen sesión y no dibujan barra lateral.
        $this->assertFalse(str_starts_with('api.asistencia.ping', 'asistencia.'));
    }

    // ──────────────────────────── Apagado seguro ────────────────────────────

    /**
     * @param  string  $ruta  nombre de ruta del módulo
     */
    #[DataProvider('pantallasDelArea')]
    public function test_con_el_modulo_apagado_ninguna_pantalla_existe(string $ruta): void
    {
        config()->set('asistencia.enabled', false);

        $this->actingAs($this->admin())
            ->get(route($ruta))
            ->assertNotFound();
    }

    public function test_con_el_modulo_apagado_tampoco_se_puede_escribir(): void
    {
        config()->set('asistencia.enabled', false);

        $this->actingAs($this->admin())
            ->post(route('asistencia.empleados.store'), ['nombres' => 'Ana', 'apellidos' => 'Pérez'])
            ->assertNotFound();

        $this->assertSame(0, AsistenciaEmpleado::count());
    }

    public function test_con_el_modulo_apagado_el_area_no_aparece_en_el_selector(): void
    {
        config()->set('asistencia.enabled', false);
        $admin = $this->admin();

        $this->assertNotContains(AreaSistema::Asistencia, AreaSistema::visiblesPara($admin));

        // Pero sigue siendo un área a la que PERTENECE por permisos: es la
        // distinción entre «no le toca» y «su área está apagada».
        $this->assertContains(AreaSistema::Asistencia, AreaSistema::potencialesPara($admin));
    }

    public function test_con_el_modulo_encendido_el_area_aparece_para_quien_tiene_el_permiso(): void
    {
        $this->assertContains(AreaSistema::Asistencia, AreaSistema::visiblesPara($this->admin()));

        // Y no aparece para quien no lo tiene.
        $sinPermiso = User::factory()->create(['activo' => true]);
        $this->assertNotContains(AreaSistema::Asistencia, AreaSistema::visiblesPara($sinPermiso));
    }

    // ───────────────────────────── Navegación ─────────────────────────────

    public function test_la_barra_lateral_del_area_se_dibuja_sin_enlaces_muertos(): void
    {
        $respuesta = $this->actingAs($this->admin())->get(route('asistencia.dashboard'))->assertOk();

        $respuesta->assertSee(route('asistencia.empleados.index'));
        $respuesta->assertSee(route('asistencia.dispositivos.index'));
        $respuesta->assertSee(route('asistencia.marcaciones.index'));

        // Y NO promete pantallas que no existen todavía. Se busca el CIERRE DE UN
        // ENLACE y no la palabra suelta: la pantalla sí menciona en prosa lo que
        // falta por construir —y debe poder hacerlo—; lo que no puede es ofrecer
        // un enlace que lleve a ninguna parte.
        $respuesta->assertDontSee('Reportes</a>', escape: false);
        $respuesta->assertDontSee('Enrolamiento</a>', escape: false);
        $respuesta->assertDontSee('Jornadas</a>', escape: false);
    }

    /**
     * Quien no administra lectores no ve su enlace. Ocultar no autoriza —el
     * middleware sigue mandando— pero ofrecer una puerta que devuelve 403 es peor
     * que no ofrecerla.
     */
    public function test_sin_permiso_de_lectores_no_se_ofrece_su_enlace(): void
    {
        $this->actingAs($this->soloVer())
            ->get(route('asistencia.dashboard'))
            ->assertOk()
            ->assertDontSee(route('asistencia.dispositivos.index'));
    }

    // ───────────────────────────── Dashboard ─────────────────────────────

    public function test_el_dashboard_muestra_el_estado_vacio_cuando_no_hay_nada(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.dashboard'))
            ->assertOk()
            ->assertSee('Todavía no hay nada configurado');
    }

    public function test_el_dashboard_cuenta_lo_que_existe(): void
    {
        AsistenciaDispositivo::factory()->create();
        AsistenciaEmpleado::factory()->count(3)->create();
        AsistenciaEmpleado::factory()->inactivo()->create();

        $this->actingAs($this->admin())
            ->get(route('asistencia.dashboard'))
            ->assertOk()
            ->assertDontSee('Todavía no hay nada configurado')
            ->assertSee('Personas activas')
            // 3 activas de 4 dadas de alta, y las 3 sin ranura.
            ->assertSee('3 persona(s) activa(s) sin ranura: no pueden marcar.');
    }

    /**
     * El dashboard NO inventa indicadores. Que no aparezcan estas palabras es la
     * garantía de que nadie agregó «tardanzas» sin tener horarios con los que
     * calcularlas.
     */
    public function test_el_dashboard_no_muestra_indicadores_que_no_se_pueden_calcular(): void
    {
        AsistenciaDispositivo::factory()->create();

        $respuesta = $this->actingAs($this->admin())->get(route('asistencia.dashboard'))->assertOk();

        foreach (['Tardanzas', 'Ausencias', 'Horas trabajadas', 'Puntualidad'] as $inventado) {
            $respuesta->assertDontSee($inventado);
        }
    }

    /**
     * TODAS las pantallas del área renderizan. Es un test de humo y hace falta: la
     * mitad de estas vistas no aparece en ningún otro caso —los de autorización
     * comprueban el 403, que se devuelve ANTES de renderizar nada—, así que una
     * variable mal escrita en un formulario podía llegar viva hasta el navegador.
     */
    public function test_todas_las_pantallas_del_area_renderizan(): void
    {
        $admin = $this->admin();
        $empleado = AsistenciaEmpleado::factory()->create();
        $lector = AsistenciaDispositivo::factory()->create();

        $pantallas = [
            route('asistencia.dashboard'),
            route('asistencia.empleados.index'),
            route('asistencia.empleados.create'),
            route('asistencia.empleados.show', $empleado),
            route('asistencia.empleados.edit', $empleado),
            route('asistencia.dispositivos.index'),
            route('asistencia.dispositivos.create'),
            route('asistencia.dispositivos.edit', $lector),
            route('asistencia.dispositivos.rotar-token', $lector),
        ];

        foreach ($pantallas as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    // ──────────────────────────────── Acceso ────────────────────────────────

    #[DataProvider('pantallasDelArea')]
    public function test_un_invitado_va_al_login(string $ruta): void
    {
        $this->get(route($ruta))->assertRedirect(route('login'));
    }

    #[DataProvider('pantallasDelArea')]
    public function test_sin_permiso_de_entrada_no_se_entra(string $ruta): void
    {
        $this->actingAs(User::factory()->create(['activo' => true]))
            ->get(route($ruta))
            ->assertForbidden();
    }

    /** @return array<string, array{0: string}> */
    public static function pantallasDelArea(): array
    {
        return [
            'resumen' => ['asistencia.dashboard'],
            'empleados' => ['asistencia.empleados.index'],
            'lectores' => ['asistencia.dispositivos.index'],
        ];
    }
}
