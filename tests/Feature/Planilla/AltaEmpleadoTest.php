<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaSueldo;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AltaEmpleadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true]);
    }

    private function actor(?string $sin = null): User
    {
        $actor = User::factory()->create(['activo' => true]);
        $actor->syncPermissions(array_values(array_diff(['planilla.ver', 'planilla.gestionar', 'planilla.salarios', 'usuarios.gestionar', 'asistencia.gestionar', 'rutas.personal.gestionar'], [$sin])));

        return $actor;
    }

    private function datos(array $cambios = []): array
    {
        return array_replace([
            'nombres' => 'Elena Lucía', 'apellidos' => 'Prueba Ficticia', 'documento_tipo' => 'dui',
            'dui' => '00000000-0', 'con_descuentos_ley' => false, 'alta_el' => '2026-01-01',
            'usa_sistema' => false, 'marca' => false, 'sale_rutas' => false,
            'rol' => 'contabilidad', 'email' => 'elena@example.test',
            'password' => 'Prueba#Segura2026', 'password_confirmation' => 'Prueba#Segura2026',
        ], $cambios);
    }

    public static function combinaciones(): array
    {
        $casos = [];
        foreach ([false, true] as $sistema) {
            foreach ([false, true] as $marca) {
                foreach ([false, true] as $rutas) {
                    foreach ([false, true] as $descuentos) {
                        $casos[] = [$sistema, $marca, $rutas, $descuentos];
                    }
                }
            }
        }

        return $casos;
    }

    #[DataProvider('combinaciones')]
    public function test_combinaciones(bool $sistema, bool $marca, bool $rutas, bool $descuentos): void
    {
        $actor = $this->actor();
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['usa_sistema' => $sistema, 'marca' => $marca, 'sale_rutas' => $rutas, 'con_descuentos_ley' => $descuentos]));
        $this->assertSame(1, PlanillaEmpleado::count());
        $this->assertSame($descuentos, $e->con_descuentos_ley);
        $this->assertSame($sistema ? 2 : 1, User::count());
        $this->assertSame((int) $marca, AsistenciaEmpleado::count());
        $this->assertSame((int) $rutas, PersonalRuta::count());
        $this->assertSame($sistema, $e->user_id !== null);
        $this->assertSame($marca, $e->asistencia_empleado_id !== null);
        $this->assertSame($rutas, $e->personal_ruta_id !== null);
        if ($marca) {
            $this->assertSame($e->user_id, $e->asistencia->user_id);
        }
        if ($rutas) {
            $this->assertNull($e->personalRuta->user_id);
            $this->assertSame($e->asistencia_empleado_id, $e->personalRuta->asistencia_empleado_id);
            $this->assertSame('vendedor', $e->personalRuta->funciones->sole()->funcion->value);
        }
    }

    public function test_vendedor_fuerza_rutas_sin_duplicar(): void
    {
        $ruta = PersonalRuta::create(['nombre' => 'Elena']);
        $e = app(AltaEmpleado::class)->registrar($this->actor(), $this->datos(['usa_sistema' => true, 'rol' => 'vendedor', 'personal_ruta_id' => $ruta->id]));
        $this->assertSame(1, PersonalRuta::count());
        $this->assertSame($e->user_id, $ruta->fresh()->user_id);
        $this->assertSame('vendedor', $ruta->fresh()->funciones->sole()->funcion->value);
        $this->assertTrue($e->usuario->hasRole('vendedor'));
    }

    public function test_vendedor_crea_su_ficha_y_enlace_inactivo_conserva_estado(): void
    {
        $actor = $this->actor();
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['usa_sistema' => true, 'rol' => 'vendedor']));
        $this->assertSame(1, PersonalRuta::count());
        $this->assertSame($e->user_id, $e->personalRuta->user_id);
        $ruta = PersonalRuta::create(['nombre' => 'Otra Ficticia', 'activo' => false]);
        $otro = app(AltaEmpleado::class)->registrar($actor, $this->datos([
            'nombres' => 'Otra', 'apellidos' => 'Ficticia', 'dui' => '00000001-8',
            'usa_sistema' => true, 'rol' => 'vendedor', 'email' => 'otra@example.test', 'personal_ruta_id' => $ruta->id,
        ]));
        $this->assertSame($otro->user_id, $ruta->fresh()->user_id);
        $this->assertFalse($ruta->fresh()->activo);
        $this->assertSame(2, PersonalRuta::count());
    }

    public function test_no_vendedor_con_rutas_no_se_apaga_al_editar_usuario(): void
    {
        $actor = $this->actor();
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['usa_sistema' => true, 'sale_rutas' => true]));
        $this->actingAs($actor)->put(route('usuarios.update', $e->user_id), [
            'name' => $e->nombre, 'email' => 'elena@example.test', 'rol' => 'contabilidad', 'activo' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($e->personalRuta->user_id);
        $this->assertTrue($e->personalRuta->fresh()->activo);
    }

    public static function permisos(): array
    {
        return [
            ['planilla.gestionar', []], ['planilla.salarios', ['sueldo_mensual' => '100.00']],
            ['usuarios.gestionar', ['usa_sistema' => true]], ['asistencia.gestionar', ['marca' => true]],
            ['rutas.personal.gestionar', ['sale_rutas' => true]],
            ['rutas.personal.gestionar', ['usa_sistema' => true, 'rol' => 'vendedor']],
        ];
    }

    #[DataProvider('permisos')]
    public function test_cada_permiso_es_obligatorio(string $permiso, array $datos): void
    {
        $actor = $this->actor($permiso);
        try {
            app(AltaEmpleado::class)->registrar($actor, $this->datos($datos));
            $this->fail('Debía exigir el permiso.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, PlanillaEmpleado::count());
    }

    public function test_http_sin_gestionar_y_actor_inactivo(): void
    {
        $actor = $this->actor('planilla.gestionar');
        $actor->assignRole('contabilidad');
        $this->actingAs($actor)->get(route('planilla.empleados.create'))->assertForbidden();
        $this->post(route('planilla.empleados.store'), $this->datos())->assertForbidden();
        $actor = $this->actor();
        $actor->update(['activo' => false]);
        $this->expectException(HttpException::class);
        app(AltaEmpleado::class)->registrar($actor, $this->datos());
    }

    public function test_sin_opcion_descuentos_no_es_valido(): void
    {
        $datos = $this->datos();
        unset($datos['con_descuentos_ley']);
        $this->actingAs($this->actor())->post(route('planilla.empleados.store'), $datos)->assertSessionHasErrors('con_descuentos_ley');
        $this->assertSame(0, PlanillaEmpleado::count());
    }

    public function test_documentos_validacion_normalizacion_y_unicidad(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post(route('planilla.empleados.store'), $this->datos(['dui' => '12345678-0']))->assertSessionHasErrors('dui');
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['dui' => '0000 0000-0']));
        $this->assertSame('00000000-0', $e->dui);
        $this->post(route('planilla.empleados.store'), $this->datos(['dui' => '000000000']))->assertSessionHasErrors('dui');
        DB::table('planilla_empleados')->where('id', $e->id)->update(['dui' => '0000 0000-0']);
        $this->post(route('planilla.empleados.store'), $this->datos(['dui' => '00000000-0']))->assertSessionHasErrors('dui');
        $p = app(AltaEmpleado::class)->registrar($actor, $this->datos(['documento_tipo' => 'pasaporte', 'dui' => 'AB-FICTICIO-7', 'es_otra_persona' => true]));
        $this->assertSame('AB-FICTICIO-7', $p->dui);
        $this->assertSame('pasaporte', $p->documento_tipo);
    }

    public function test_coincidencia_apellido_enlace_y_otra_persona(): void
    {
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Élena María', 'apellidos' => 'Prueba Segunda', 'activo' => true]);
        $actor = $this->actor();
        $this->actingAs($actor)->post(route('planilla.empleados.store'), $this->datos())->assertSessionHasErrors('coincidencias');
        $this->assertSame(0, PlanillaEmpleado::count());
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['asistencia_empleado_id' => $asistencia->id]));
        $this->assertSame($asistencia->id, $e->asistencia_empleado_id);
        $otro = app(AltaEmpleado::class)->registrar($actor, $this->datos(['documento_tipo' => 'pasaporte', 'dui' => 'OTRA-FICTICIA', 'es_otra_persona' => true]));
        $this->assertNotSame($e->id, $otro->id);
    }

    public function test_coincidencias_en_los_cuatro_catalogos_y_ruta_solo_nombre(): void
    {
        $actor = $this->actor();
        AsistenciaEmpleado::create(['nombres' => 'Elena María', 'apellidos' => 'Prueba', 'activo' => true]);
        PersonalRuta::create(['nombre' => 'Elena']);
        User::factory()->create(['name' => 'Elena Lucía Prueba']);
        app(RegistrarEmpleado::class)->registrar($actor, ['nombre' => 'Elena Prueba']);
        $coincidencias = app(AltaEmpleado::class)->coincidencias('Élena Lucía', 'Prueba Ficticia');
        $this->assertCount(4, $coincidencias);
        $this->assertEqualsCanonicalizing(['asistencia_empleado_id', 'personal_ruta_id', 'user_id', 'planilla_empleado_id'], array_column($coincidencias, 'tipo'));
    }

    public function test_enlaces_no_reactivan_y_casillas_no_duplican(): void
    {
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Elena', 'apellidos' => 'Prueba', 'activo' => false]);
        $ruta = PersonalRuta::create(['nombre' => 'Elena', 'activo' => false]);
        $usuario = User::factory()->create(['name' => 'Elena Prueba', 'activo' => false]);
        $e = app(AltaEmpleado::class)->registrar($this->actor(), $this->datos(['marca' => true, 'sale_rutas' => true, 'usa_sistema' => true, 'asistencia_empleado_id' => $asistencia->id, 'personal_ruta_id' => $ruta->id, 'user_id' => $usuario->id]));
        $this->assertFalse($e->asistencia->activo);
        $this->assertFalse($e->personalRuta->activo);
        $this->assertFalse($e->usuario->activo);
        $this->assertSame(1, AsistenciaEmpleado::count());
        $this->assertSame(1, PersonalRuta::count());
        $this->assertSame(2, User::count());
    }

    public function test_conflicto_de_punteros_revierte_todo(): void
    {
        $otro = User::factory()->create();
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Elena', 'apellidos' => 'Prueba', 'user_id' => $otro->id, 'activo' => true]);
        $actor = $this->actor();
        $usuarios = User::count();
        $actividades = Activity::count();
        try {
            app(AltaEmpleado::class)->registrar($actor, $this->datos(['usa_sistema' => true, 'sale_rutas' => true, 'asistencia_empleado_id' => $asistencia->id, 'sueldo_mensual' => '500.00']));
            $this->fail('Debía detectar el enlace incompatible.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
        }
        $this->assertSame(0, PlanillaEmpleado::count());
        $this->assertSame(0, PlanillaSueldo::count());
        $this->assertSame(0, PersonalRuta::count());
        $this->assertSame($usuarios, User::count());
        $this->assertSame($actividades, Activity::count());
    }

    public function test_fallo_de_creacion_usuario_no_deja_filas(): void
    {
        $actor = $this->actor();
        $this->mock(CrearUsuario::class)->shouldReceive('crear')->once()->andThrow(new \RuntimeException('Fallo ficticio'));
        try {
            app(AltaEmpleado::class)->registrar($actor, $this->datos(['usa_sistema' => true, 'marca' => true, 'sale_rutas' => true, 'sueldo_mensual' => '500.00']));
            $this->fail('Debía fallar.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo ficticio', $e->getMessage());
        }
        $this->assertSame(0, PlanillaEmpleado::count());
        $this->assertSame(0, PlanillaSueldo::count());
        $this->assertSame(0, AsistenciaEmpleado::count());
        $this->assertSame(0, PersonalRuta::count());
        $this->assertSame(1, User::count());
    }

    public function test_enlaces_exigen_permiso_aunque_no_se_marque_casilla(): void
    {
        $usuario = User::factory()->create(['name' => 'Cuenta Ficticia']);
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Ficha', 'apellidos' => 'Ficticia']);
        $ruta = PersonalRuta::create(['nombre' => 'Ruta Ficticia']);
        foreach (['usuarios.gestionar' => ['user_id' => $usuario->id], 'asistencia.gestionar' => ['asistencia_empleado_id' => $asistencia->id], 'rutas.personal.gestionar' => ['personal_ruta_id' => $ruta->id]] as $permiso => $datos) {
            $actor = $this->actor($permiso);
            $this->actingAs($actor)->post(route('planilla.empleados.store'), $this->datos($datos))->assertForbidden();
        }
        $this->assertSame(0, PlanillaEmpleado::count());
    }

    public function test_enlaces_deben_existir_y_no_estar_en_otra_planilla(): void
    {
        $actor = $this->actor();
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['usa_sistema' => true, 'marca' => true, 'sale_rutas' => true]));
        foreach (['user_id', 'asistencia_empleado_id', 'personal_ruta_id'] as $campo) {
            $this->actingAs($actor)->post(route('planilla.empleados.store'), $this->datos(['documento_tipo' => 'pasaporte', 'dui' => 'NUEVO-FICTICIO', 'es_otra_persona' => true, $campo => $e->$campo]))->assertSessionHasErrors($campo);
            $this->post(route('planilla.empleados.store'), $this->datos(['documento_tipo' => 'pasaporte', 'dui' => 'NUEVO-FICTICIO', $campo => 999999]))->assertSessionHasErrors($campo);
        }
        $this->assertSame(1, PlanillaEmpleado::count());
    }

    public function test_http_alta_listado_y_confidencialidad_del_sueldo(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post(route('planilla.empleados.store'), $this->datos(['sueldo_mensual' => '876.54']))->assertSessionHasNoErrors()->assertRedirect(route('personal.empleados.show', 'p'.PlanillaEmpleado::sole()->id));
        $this->get(route('personal.empleados'))->assertOk()->assertSee('Nuevo empleado')->assertSee('876.54');
        $lector = $this->actor('planilla.salarios');
        $this->actingAs($lector)->get(route('personal.empleados'))->assertOk()->assertDontSee('876.54')->assertDontSee('Sueldo mensual');
    }

    public function test_migracion_aditiva_se_revierte_en_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $migracion = require database_path('migrations/2026_11_02_100000_agregar_alta_unica_a_planilla_empleados.php');
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('planilla_empleados', 'documento_tipo'));
        $this->assertFalse(Schema::hasColumn('planilla_detalles', 'documento_tipo_snapshot'));
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('planilla_empleados', 'dependientes'));
        $this->assertTrue(Schema::hasColumn('planilla_detalles', 'documento_tipo_snapshot'));
    }

    public function test_sueldo_mensual_propone_importe_y_snapshot_al_abrir(): void
    {
        $actor = $this->actor();
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['sueldo_mensual' => '500.01', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-8']));
        $this->assertDatabaseHas('planilla_sueldos', ['planilla_empleado_id' => $e->id, 'importe' => '250.01']);
        $planilla = app(PrepararPlanilla::class)->abrir($actor, ['tipo_periodo' => 'quincenal', 'fecha' => '2026-11-02', 'clase' => 'regular', 'moneda' => 'USD']);
        $detalle = $planilla->detalles->sole();
        $this->assertSame('250.01', $detalle->salario);
        $this->assertSame('pasaporte', $detalle->documento_tipo_snapshot);
        $this->assertSame('FICTICIO-8', $detalle->dui_snapshot);
        foreach (['hoja', 'recibo'] as $formato) {
            $this->actingAs($actor)->get(route('planilla.impresos', [$planilla, 'formato' => $formato]))->assertOk()->assertSee('Pasaporte FICTICIO-8')->assertDontSee('DUI FICTICIO-8');
        }
        $anticipo = app(RegistrarAnticipo::class)->registrar($actor, $e, ['fecha' => '2026-11-02', 'importe' => '10.00', 'moneda' => 'USD']);
        $this->get(route('planilla.anticipos.comprobante', $anticipo))->assertOk()->assertSee('Pasaporte FICTICIO-8')->assertDontSee('DUI FICTICIO-8');
        $detalle->update(['documento_tipo_snapshot' => null]);
        $this->get(route('planilla.impresos', $planilla))->assertOk()->assertSee('DUI FICTICIO-8');
    }

    public function test_contadora_externa_se_crea_en_usuarios_sin_planilla(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->get(route('planilla.empleados.create'))->assertOk()->assertSee('Usuarios › Nuevo')->assertSee(route('usuarios.create'));
        $this->post(route('usuarios.store'), ['name' => 'Contadora Externa Ficticia', 'email' => 'externa@example.test', 'password' => 'Prueba#Segura2026', 'password_confirmation' => 'Prueba#Segura2026', 'rol' => 'contabilidad', 'activo' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, PlanillaEmpleado::count());
        $this->assertSame(0, PersonalRuta::count());
        $this->assertSame(0, AsistenciaEmpleado::count());
    }

    public function test_actividad_solo_casillas_sin_datos_sensibles(): void
    {
        $actor = $this->actor();
        $e = app(AltaEmpleado::class)->registrar($actor, $this->datos(['usa_sistema' => true, 'sueldo_mensual' => '831.20']));
        $actividad = Activity::where('log_name', 'empleado')->sole();
        $this->assertSame($e->id, $actividad->subject_id);
        $this->assertSame($actor->id, $actividad->causer_id);
        $this->assertEqualsCanonicalizing(['usa_sistema', 'marca', 'sale_rutas', 'con_descuentos_ley'], array_keys($actividad->properties->all()));
        $json = DB::table('activity_log')->get()->toJson();
        foreach (['831.20', '00000000-0', 'Prueba#Segura2026', 'password', 'sueldo_mensual'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $json);
        }
    }
}
