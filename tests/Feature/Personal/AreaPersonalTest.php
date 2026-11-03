<?php

namespace Tests\Feature\Personal;

use App\Enums\AreaSistema;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Asistencia y Planilla en una sola área, con una sola lista de empleados.
 *
 * Lo que defiende este archivo: juntar la NAVEGACIÓN no junta los PERMISOS, cada módulo se
 * apaga por su lado, y el lector de huellas no se enteró de nada.
 */
class AreaPersonalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['asistencia.enabled' => true, 'planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->admin = $this->usuario(PermisoSistema::todos());
    }

    /** @param  array<int, string>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Persona en planilla que además marca, con sueldo y documento ficticios. */
    private function enPlanilla(array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, array_replace([
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '876.54', 'marca' => true,
        ], $cambios));
    }

    private function sidebar(string $url, User $usuario): string
    {
        $html = $this->actingAs($usuario)->get($url)->assertOk()->getContent();
        $inicio = strpos($html, '<aside');

        return substr($html, $inicio, strpos($html, '</aside>') - $inicio);
    }

    public function test_el_area_se_llama_personal_y_conserva_su_value(): void
    {
        $this->assertSame('Personal', AreaSistema::Asistencia->label());
        $this->assertSame('asistencia', AreaSistema::Asistencia->value);
    }

    public function test_las_tres_familias_de_pantallas_pintan_la_misma_barra(): void
    {
        foreach (['asistencia.dashboard', 'planilla.index', 'personal.empleados'] as $ruta) {
            $this->actingAs($this->admin)->get(route($ruta))->assertOk();
            $this->assertSame(AreaSistema::Asistencia, AreaSistema::activaDesdeRequest(), $ruta);
        }
    }

    public function test_el_menu_junta_todo_para_quien_tiene_todo(): void
    {
        $sidebar = $this->sidebar(route('personal.empleados'), $this->admin);

        foreach (['asistencia.dashboard', 'personal.empleados', 'asistencia.reporte', 'planilla.index', 'planilla.anticipos'] as $ruta) {
            $this->assertStringContainsString(route($ruta), $sidebar, "Falta $ruta en el menú.");
        }
    }

    /** @return array<string, array{0: bool, 1: bool}> */
    public static function interruptores(): array
    {
        return [
            'los dos encendidos' => [true, true],
            'solo asistencia' => [true, false],
            'solo planilla' => [false, true],
        ];
    }

    #[DataProvider('interruptores')]
    public function test_cada_combinacion_de_interruptores_abre_lo_suyo(bool $asistencia, bool $planilla): void
    {
        $persona = $this->enPlanilla();
        $soloMarca = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ejemplo']);
        config(['asistencia.enabled' => $asistencia, 'planilla.enabled' => $planilla]);

        $this->assertTrue(AreaSistema::Asistencia->habilitada());
        $this->assertContains(AreaSistema::Asistencia, AreaSistema::visiblesPara($this->admin));
        $this->assertSame($asistencia ? 'asistencia.dashboard' : 'planilla.index', AreaSistema::Asistencia->rutaInicioPara($this->admin));

        $sidebar = $this->sidebar(route('personal.empleados'), $this->admin);

        $this->assertSame($asistencia, str_contains($sidebar, route('asistencia.reporte')));
        $this->assertSame($planilla, str_contains($sidebar, route('planilla.index')));

        $lista = $this->get(route('personal.empleados'))->assertOk();
        $asistencia ? $lista->assertSee('Beto Ejemplo') : $lista->assertDontSee('Beto Ejemplo');
        $planilla ? $lista->assertSee('Descuentos') : $lista->assertDontSee('Descuentos');
        $asistencia ? $lista->assertSee('Huellas') : $lista->assertDontSee('Huellas');

        $this->get(route('asistencia.dashboard'))->assertStatus($asistencia ? 200 : 404);
        $this->get(route('planilla.index'))->assertStatus($planilla ? 200 : 404);

        // La ficha de cada uno abre solo si su módulo está encendido.
        $this->get(route('personal.empleados.show', 'a'.$persona->asistencia_empleado_id))->assertOk();
        $this->get(route('personal.empleados.show', 'a'.$soloMarca->id))->assertStatus($asistencia ? 200 : 403);
        $this->get(route('personal.empleados.show', 'p'.$persona->id))->assertOk();
    }

    public function test_con_los_dos_apagados_el_area_desaparece(): void
    {
        config(['asistencia.enabled' => false, 'planilla.enabled' => false]);

        $this->assertFalse(AreaSistema::Asistencia->habilitada());
        $this->assertNotContains(AreaSistema::Asistencia, AreaSistema::visiblesPara($this->admin));
        $this->actingAs($this->admin)->get(route('personal.empleados'))->assertNotFound();
    }

    public function test_el_permiso_de_una_puerta_apagada_no_abre_la_otra(): void
    {
        config(['planilla.enabled' => false]);

        $encargada = $this->usuario(['planilla.ver', 'dashboard.ver']);

        $this->assertNotContains(AreaSistema::Asistencia, AreaSistema::visiblesPara($encargada));
        $this->actingAs($encargada)->get(route('personal.empleados'))->assertForbidden();
    }

    public function test_una_sola_fila_por_persona_aunque_este_en_los_dos_modulos(): void
    {
        $e = $this->enPlanilla();
        AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ejemplo']);

        $html = $this->actingAs($this->admin)->get(route('personal.empleados'))->assertOk()->assertSee('Beto Ejemplo')->getContent();

        $this->assertSame(2, substr_count($html, route('personal.empleados.show', 'p'.$e->id)));
        $this->assertStringNotContainsString(route('personal.empleados.show', 'a'.$e->asistencia_empleado_id), $html);
    }

    public function test_filtra_y_cuenta_solo_huellas_vigentes(): void
    {
        $this->enPlanilla();
        $beto = AsistenciaEmpleado::factory()->inactivo()->create(['nombres' => 'Beto', 'apellidos' => 'Ejemplo']);
        AsistenciaHuella::factory()->liberada()->create([
            'asistencia_empleado_id' => $beto->id,
            'asistencia_dispositivo_id' => AsistenciaDispositivo::factory()->create()->id,
            'fingerprint_id' => 5,
        ]);

        $this->actingAs($this->admin);
        $this->get(route('personal.empleados', ['q' => 'beto']))->assertSee('Beto Ejemplo')->assertSee('Sin huella')->assertDontSee('Ana Ficticia');
        $this->get(route('personal.empleados', ['activo' => '1']))->assertSee('Ana Ficticia')->assertDontSee('Beto Ejemplo');
    }

    public function test_quien_solo_tiene_asistencia_no_ve_sueldos_ni_documentos(): void
    {
        $e = $this->enPlanilla();
        $usuario = $this->usuario(['asistencia.ver']);

        $this->actingAs($usuario)->get(route('personal.empleados'))->assertOk()
            ->assertSee('Ana Ficticia')->assertSee('Huellas')
            ->assertDontSee('876.54')->assertDontSee('Sueldo mensual')->assertDontSee('Descuentos');

        $this->get(route('personal.empleados.show', 'a'.$e->asistencia_empleado_id))->assertOk()
            ->assertSee('Huellas vigentes')
            ->assertDontSee('876.54')->assertDontSee('FICTICIO-1')->assertDontSee('Últimas quincenas');

        // Con la clave de planilla ve lo mismo: su enlace de Asistencia, nada más.
        $this->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()->assertDontSee('FICTICIO-1');

        $this->get(route('planilla.index'))->assertForbidden();
    }

    public function test_quien_solo_tiene_planilla_no_ve_huellas_ni_importes(): void
    {
        $e = $this->enPlanilla();
        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ejemplo']);
        $usuario = $this->usuario(['planilla.ver']);

        $this->actingAs($usuario)->get(route('personal.empleados'))->assertOk()
            ->assertSee('Ana Ficticia')->assertSee('Descuentos')
            ->assertDontSee('Huellas')->assertDontSee('Beto Ejemplo')->assertDontSee('876.54');

        $this->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('Últimas quincenas')
            ->assertDontSee('Huellas vigentes')->assertDontSee('Últimas marcaciones')->assertDontSee('876.54')->assertDontSee('Cambiar sueldo');

        $this->get(route('personal.empleados.show', 'a'.$beto->id))->assertForbidden();
        $this->get(route('asistencia.dashboard'))->assertForbidden();
    }

    public function test_quien_tiene_salarios_ve_el_sueldo(): void
    {
        $e = $this->enPlanilla();
        $usuario = $this->usuario(['planilla.ver', 'planilla.salarios']);

        $this->actingAs($usuario)->get(route('personal.empleados'))->assertSee('876.54');
        $this->get(route('personal.empleados.show', 'p'.$e->id))->assertSee('876.54')->assertDontSee('Dar de baja');
    }

    public function test_sin_permisos_no_entra(): void
    {
        $this->actingAs($this->usuario(['dashboard.ver']))->get(route('personal.empleados'))->assertForbidden();
    }

    public function test_un_usuario_desactivado_no_entra(): void
    {
        $inactivo = User::factory()->create(['activo' => false]);
        $inactivo->syncPermissions(['asistencia.ver']);

        $this->actingAs($inactivo)->get(route('personal.empleados'))->assertRedirect();
    }

    public function test_la_ficha_junta_datos_huellas_quincenas_y_acciones(): void
    {
        $e = $this->enPlanilla();

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$e->id))->assertOk()
            ->assertSee('FICTICIO-1')->assertSee('876.54')->assertSee('Huellas vigentes')->assertSee('Últimas marcaciones')
            ->assertSee('Últimas quincenas')->assertSee('Cambiar sueldo')->assertSee('Dar de baja')->assertSee('Contrato');
    }

    public function test_la_ficha_de_alguien_que_no_existe_da_404(): void
    {
        $this->actingAs($this->admin);
        $this->get('/personal/empleados/p999')->assertNotFound();
        $this->get('/personal/empleados/a999')->assertNotFound();
        $this->get('/personal/empleados/x1')->assertNotFound();
    }

    public function test_las_direcciones_viejas_redirigen_para_siempre(): void
    {
        $e = $this->enPlanilla();
        $this->actingAs($this->admin);

        $this->get('/asistencia/empleados?q=ana')->assertStatus(301)->assertRedirect(route('personal.empleados', ['q' => 'ana']));
        $this->get('/asistencia/empleados/'.$e->asistencia_empleado_id)->assertStatus(301)
            ->assertRedirect(route('personal.empleados.show', 'a'.$e->asistencia_empleado_id));
        $this->get('/planilla/empleados')->assertStatus(301)->assertRedirect(route('personal.empleados'));
    }

    public function test_las_redirecciones_conservan_el_candado(): void
    {
        $e = $this->enPlanilla();
        $this->actingAs($this->usuario(['dashboard.ver']));

        $this->get('/asistencia/empleados')->assertForbidden();
        $this->get('/asistencia/empleados/'.$e->asistencia_empleado_id)->assertForbidden();
        $this->get('/planilla/empleados')->assertForbidden();
    }

    public function test_crear_y_editar_siguen_en_su_lugar(): void
    {
        $e = $this->enPlanilla();
        $this->actingAs($this->admin);

        $this->get(route('asistencia.empleados.create'))->assertOk();
        $this->get(route('asistencia.empleados.edit', $e->asistencia_empleado_id))->assertOk();
        $this->get(route('planilla.empleados.create'))->assertOk();
    }

    public function test_el_boton_nuevo_empleado_usa_el_alta_que_corresponde(): void
    {
        $this->actingAs($this->admin)->get(route('personal.empleados'))
            ->assertSee(route('planilla.empleados.create'))->assertDontSee(route('asistencia.empleados.create'));

        config(['planilla.enabled' => false]);

        $this->get(route('personal.empleados'))
            ->assertSee(route('asistencia.empleados.create'))->assertDontSee(route('planilla.empleados.create'));
    }

    public function test_el_alta_de_asistencia_lleva_a_la_ficha_con_su_aviso(): void
    {
        config(['planilla.enabled' => false]);

        $this->actingAs($this->admin)->followingRedirects()
            ->post(route('asistencia.empleados.store'), ['nombres' => 'Beto', 'apellidos' => 'Ejemplo'])
            ->assertOk()->assertSee('dado de alta')->assertSee('Huellas vigentes');
    }

    public function test_el_lector_no_cambio_ni_redirige(): void
    {
        $esperadas = [
            'api.asistencia.ping' => 'api/asistencia/ping',
            'api.asistencia.marcar' => 'api/asistencia/marcar',
            'api.asistencia.enrolamiento.pendiente' => 'api/asistencia/enrolamiento/pendiente',
        ];

        foreach ($esperadas as $nombre => $uri) {
            $this->assertSame($uri, Route::getRoutes()->getByName($nombre)?->uri(), $nombre);
        }

        // Sin token responde el candado del lector, y nunca una redirección.
        $this->postJson('/api/asistencia/marcar', ['fingerprint_id' => 1])->assertUnauthorized();
        $this->getJson('/api/asistencia/enrolamiento/pendiente')->assertUnauthorized();
    }

    public function test_el_lector_sigue_apagado_con_asistencia_apagada_aunque_planilla_siga(): void
    {
        config(['asistencia.enabled' => false]);

        $this->postJson('/api/asistencia/marcar', ['fingerprint_id' => 1])->assertNotFound();
    }
}
