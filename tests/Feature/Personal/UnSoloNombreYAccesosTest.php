<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La ficha de Personal manda: su nombre es el de la persona en Asistencia, Rutas y su
 * usuario, y el acceso de un empleado se da solo desde ahí. Personal › Accesos crea solo
 * cuentas externas.
 */
class UnSoloNombreYAccesosTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'Clave-Ficticia-2026';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['asistencia.enabled' => true, 'planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->admin = User::factory()->create(['activo' => true, 'name' => 'Admin Inventado']);
        $this->admin->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = $this->admin->fresh();
    }

    private function alta(array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, array_replace([
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'marca' => false, 'sale_rutas' => false,
        ], $cambios));
    }

    public function test_el_alta_que_enlaza_fichas_de_antes_les_deja_el_nombre_de_la_ficha(): void
    {
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Ana', 'apellidos' => 'Ficticia López', 'activo' => true]);
        $ruta = PersonalRuta::create(['nombre' => 'Anita']);
        $usuario = User::factory()->create(['name' => 'Administrador', 'activo' => true]);

        $p = $this->alta([
            'marca' => true, 'sale_rutas' => true,
            'asistencia_empleado_id' => $asistencia->id, 'personal_ruta_id' => $ruta->id, 'user_id' => $usuario->id,
        ]);

        $this->assertSame('Ana Ficticia', $p->nombre);
        $this->assertSame(['Ana', 'Ficticia'], [$asistencia->fresh()->nombres, $asistencia->fresh()->apellidos]);
        $this->assertSame('Ana Ficticia', $ruta->fresh()->nombre);
        $this->assertSame('Ana Ficticia', $usuario->fresh()->name);
    }

    public function test_poner_a_marcar_enlaza_la_asistencia_suelta_en_vez_de_duplicarla(): void
    {
        $p = $this->alta();
        $suelta = AsistenciaEmpleado::create(['nombres' => 'Ana María', 'apellidos' => 'Ficticia', 'activo' => true]);
        AsistenciaEmpleado::create(['nombres' => 'Otra', 'apellidos' => 'Persona', 'activo' => true]);

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()
            ->assertSee('Ficha de Asistencia')->assertSeeInOrder(['Ana María Ficticia · nombre parecido', 'Otra Persona'])
            ->assertSee('Marca con el nombre de esta ficha: Ana Ficticia');

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), [
            'marca' => '1', 'asistencia_empleado_id' => (string) $suelta->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $p = $p->fresh();
        $this->assertSame($suelta->id, $p->asistencia_empleado_id);
        $this->assertSame(2, AsistenciaEmpleado::count());
        // La suelta toma el nombre de la ficha: «Hoy» la muestra igual que Personal.
        $this->assertSame('Ana Ficticia', $suelta->fresh()->nombreCompleto());
        $this->assertSame('Ana Ficticia', $p->nombre);
    }

    public function test_no_se_enlaza_una_asistencia_que_ya_es_de_otra_persona(): void
    {
        $otra = $this->alta(['marca' => true, 'nombres' => 'Bea', 'dui' => 'FICTICIO-2']);
        $p = $this->alta();

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), [
            'marca' => '1', 'asistencia_empleado_id' => (string) $otra->asistencia_empleado_id,
        ])->assertSessionHasErrors('asistencia_empleado_id');

        $this->assertNull($p->fresh()->asistencia_empleado_id);
        $this->assertSame('Bea', $otra->asistencia->fresh()->nombres);
    }

    public function test_sin_permiso_de_asistencia_no_se_enlaza_una_suelta(): void
    {
        $p = $this->alta();
        $suelta = AsistenciaEmpleado::create(['nombres' => 'Ana', 'apellidos' => 'Ficticia', 'activo' => true]);
        $sinAsistencia = User::factory()->create(['activo' => true]);
        $sinAsistencia->syncPermissions([PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaGestionar->value]);

        $this->actingAs($sinAsistencia)->post(route('planilla.empleados.modulos', $p), [
            'marca' => '1', 'asistencia_empleado_id' => (string) $suelta->id,
        ])->assertForbidden();

        $this->assertNull($p->fresh()->asistencia_empleado_id);
    }

    public function test_quien_tiene_ficha_no_cambia_su_nombre_en_el_perfil(): void
    {
        $p = $this->alta([
            'usa_sistema' => true, 'rol' => 'facturacion', 'email' => 'ana.ficticia@example.test',
            'password' => self::CLAVE, 'password_confirmation' => self::CLAVE,
        ]);
        $usuario = $p->usuario;

        $this->actingAs($usuario)->get(route('profile.edit'))->assertOk()
            ->assertSee('Tu nombre se cambia en tu ficha de Personal')->assertDontSee('name="name"', false);

        $this->actingAs($usuario)->patch(route('profile.update'), ['name' => 'Otro Nombre', 'email' => $usuario->email])
            ->assertSessionHasErrors(['name' => 'Tu nombre se cambia en tu ficha de Personal.']);
        $this->assertSame('Ana Ficticia', $usuario->fresh()->name);

        // Sin el nombre, el resto del perfil se guarda igual.
        $this->actingAs($usuario)->patch(route('profile.update'), ['email' => 'ana.nueva@example.test'])->assertSessionHasNoErrors();
        $this->assertSame(['Ana Ficticia', 'ana.nueva@example.test'], [$usuario->fresh()->name, $usuario->fresh()->email]);
    }

    public function test_la_cuenta_externa_si_cambia_su_nombre_en_el_perfil(): void
    {
        $externa = User::factory()->create(['activo' => true, 'cuenta_externa' => true, 'name' => 'Contadora Ficticia']);

        $this->actingAs($externa)->get(route('profile.edit'))->assertOk()->assertSee('name="name"', false);
        $this->actingAs($externa)->patch(route('profile.update'), ['name' => 'Contadora Nueva', 'email' => $externa->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('Contadora Nueva', $externa->fresh()->name);
    }

    /** @return array<string, string> */
    private function cuenta(array $cambios = []): array
    {
        return array_replace([
            'name' => 'Carla Inventada', 'email' => 'carla@example.test', 'rol' => 'contabilidad',
            'password' => self::CLAVE, 'password_confirmation' => self::CLAVE,
        ], $cambios);
    }

    public function test_accesos_solo_crea_cuentas_externas_aunque_planilla_este_apagada(): void
    {
        config(['planilla.enabled' => false]);
        $antes = User::count();

        $this->actingAs($this->admin)->get(route('personal.accesos.create'))->assertOk()
            ->assertSee('Nueva cuenta externa')->assertDontSee('value="vendedor"', false);
        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->cuenta(['rol' => 'vendedor', 'activo' => '1']))
            ->assertSessionHasErrors('cuenta_externa');
        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->cuenta(['rol' => 'administrador', 'cuenta_externa' => '1']))
            ->assertSessionHasErrors('rol');
        $this->assertSame($antes, User::count());

        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->cuenta(['cuenta_externa' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'carla@example.test')->sole()->cuenta_externa);
    }

    public function test_el_comando_lista_sin_escribir_y_con_aplicar_iguala_a_la_ficha(): void
    {
        $p = $this->alta(['marca' => true, 'sale_rutas' => true]);
        $p->asistencia->update(['nombres' => 'Anita']);
        $p->personalRuta->update(['nombre' => 'Ana F.']);

        $this->artisan('personal:nombres-distintos')
            ->expectsOutputToContain('Anita Ficticia')
            ->expectsOutputToContain('No se escribió nada')
            ->assertSuccessful();
        $this->assertSame('Anita', $p->asistencia->fresh()->nombres);
        $this->assertSame('Ana F.', $p->personalRuta->fresh()->nombre);

        $this->artisan('personal:nombres-distintos', ['--aplicar' => true])->assertSuccessful();
        $p = $p->fresh();
        $this->assertSame('Ana Ficticia', $p->asistencia->nombreCompleto());
        $this->assertSame('Ana Ficticia', $p->personalRuta->nombre);
        $registro = Activity::where('log_name', 'empleado')->where('subject_id', $p->id)->where('description', 'like', 'igualó su nombre%')->sole();
        // assertEquals y no assertSame: la columna JSON de MySQL reordena las claves.
        $this->assertEquals(['asistencia' => 'Anita Ficticia', 'rutas' => 'Ana F.'], $registro->properties['antes']);

        $this->artisan('personal:nombres-distintos')->expectsOutputToContain('Todas las copias coinciden')->assertSuccessful();
    }
}
