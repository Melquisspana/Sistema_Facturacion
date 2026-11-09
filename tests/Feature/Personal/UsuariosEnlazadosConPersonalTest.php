<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El acceso y la ficha son la misma persona: una cuenta de antes (de cuando existía
 * Usuarios › Nuevo) se enlaza con su ficha desde la ficha, el único lugar para eso, y
 * Personal › Accesos lleva a la ficha de cada uno.
 */
class UsuariosEnlazadosConPersonalTest extends TestCase
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

    private function persona(array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, array_replace([
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'marca' => false, 'sale_rutas' => false,
        ], $cambios));
    }

    /** Una cuenta suelta de antes, como las de los vendedores que se crearon en Usuarios › Nuevo. */
    private function cuenta(string $rol, string $nombre = 'Anita', string $correo = 'anita@icloud.com'): User
    {
        return app(CrearUsuario::class)->crear([
            'name' => $nombre, 'email' => $correo, 'password' => 'Clave-Ficticia-2026', 'activo' => true, 'rol' => $rol,
        ]);
    }

    public function test_la_ficha_enlaza_un_usuario_que_ya_existia(): void
    {
        $p = $this->persona();
        $cuenta = $this->cuenta('contabilidad');
        $usuarios = User::count();

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), [
            'usa_sistema' => '1', 'user_id' => (string) $cuenta->id, 'rol' => 'administrador', 'email' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($cuenta->id, $p->fresh()->user_id);
        $this->assertSame($usuarios, User::count());
        // Un solo nombre: queda el de Personal. El rol y el correo no cambian.
        $this->assertSame('Ana Ficticia', $cuenta->fresh()->name);
        $this->assertTrue($cuenta->fresh()->hasRole('contabilidad'));
        $this->assertSame('anita@icloud.com', $cuenta->fresh()->email);
        $this->assertNull($p->fresh()->personal_ruta_id);

        $bitacora = Activity::where('log_name', 'empleado')->where('description', 'agregó módulos al empleado')->sole();
        $this->assertSame($this->admin->id, $bitacora->causer_id);
        $this->assertTrue($bitacora->properties['usuario_existente']);
        $this->assertTrue(Activity::where('log_name', 'usuario')->where('subject_id', $cuenta->id)
            ->where('description', 'like', 'lo enlazó a la ficha de Ana Ficticia%')->exists());
    }

    public function test_un_vendedor_enlazado_usa_su_ficha_de_rutas_sin_duplicarla(): void
    {
        $p = $this->persona();
        $cuenta = $this->cuenta('vendedor');
        $ruta = $cuenta->personalRuta;
        $this->assertNotNull($ruta);

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), [
            'usa_sistema' => '1', 'user_id' => (string) $cuenta->id,
        ])->assertSessionHasNoErrors();

        $p = $p->fresh();
        $this->assertSame(1, PersonalRuta::count());
        $this->assertSame($ruta->id, $p->personal_ruta_id);
        $this->assertSame($cuenta->id, $ruta->fresh()->user_id);
        $this->assertSame('Ana Ficticia', $ruta->fresh()->nombre);
    }

    public function test_un_vendedor_con_otra_ficha_de_rutas_no_se_enlaza(): void
    {
        $p = $this->persona(['sale_rutas' => true]);
        $cuenta = $this->cuenta('vendedor');

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), [
            'usa_sistema' => '1', 'user_id' => (string) $cuenta->id,
        ])->assertSessionHasErrors('user_id');

        $this->assertNull($p->fresh()->user_id);
        $this->assertSame('Anita', $cuenta->fresh()->name);
    }

    public function test_el_alta_con_un_vendedor_que_ya_existia_usa_su_ficha_de_rutas(): void
    {
        $cuenta = $this->cuenta('vendedor', 'Beatriz', 'bea@icloud.com');

        // Sin pasar la ficha de Rutas: el vendedor trae la suya.
        $p = app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Bea', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-2',
            'con_descuentos_ley' => false, 'user_id' => $cuenta->id,
        ]);

        $this->assertSame(1, PersonalRuta::count());
        $this->assertSame($cuenta->personalRuta->id, $p->personal_ruta_id);
    }

    public function test_un_usuario_ya_enlazado_no_se_vuelve_a_enlazar(): void
    {
        $cuenta = $this->cuenta('contabilidad');
        $primera = $this->persona();
        $segunda = $this->persona(['nombres' => 'Bea', 'dui' => 'FICTICIO-2']);
        app(AltaEmpleado::class)->completar($this->admin, $primera, ['usa_sistema' => true, 'user_id' => $cuenta->id]);

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $segunda), [
            'usa_sistema' => '1', 'user_id' => (string) $cuenta->id,
        ])->assertSessionHasErrors('user_id');
        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $segunda), [
            'usa_sistema' => '1', 'user_id' => '999999',
        ])->assertSessionHasErrors('user_id');

        $this->assertNull($segunda->fresh()->user_id);
    }

    public function test_enlazar_exige_los_mismos_permisos_que_el_alta(): void
    {
        $p = $this->persona();
        $sinUsuarios = $this->usuario([PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaGestionar->value]);
        $sinRutas = $this->usuario([PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaGestionar->value, PermisoSistema::UsuariosGestionar->value]);
        $contadora = $this->cuenta('contabilidad');
        $vendedor = $this->cuenta('vendedor', 'Ven Ficticio', 'ven@icloud.com');

        $this->actingAs($sinUsuarios)->post(route('planilla.empleados.modulos', $p), ['usa_sistema' => '1', 'user_id' => (string) $contadora->id])->assertForbidden();
        // Vendedor también sale a rutas: hace falta ese permiso.
        $this->actingAs($sinRutas)->post(route('planilla.empleados.modulos', $p), ['usa_sistema' => '1', 'user_id' => (string) $vendedor->id])->assertForbidden();

        $this->assertNull($p->fresh()->user_id);
    }

    public function test_a_quien_esta_de_baja_no_se_le_agrega_nada(): void
    {
        $p = $this->persona();
        app(BajaEmpleado::class)->dar($this->admin, $p, '2026-02-01', 'otro');
        $cuenta = $this->cuenta('contabilidad');

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), ['usa_sistema' => '1', 'user_id' => (string) $cuenta->id])
            ->assertSessionHasErrors('modulos');
        $this->assertNull($p->fresh()->user_id);
    }

    public function test_la_ficha_ofrece_solo_usuarios_sin_ficha(): void
    {
        $this->cuenta('contabilidad');
        $ocupada = $this->cuenta('contabilidad', 'Ocupada', 'ocupada@icloud.com');
        app(AltaEmpleado::class)->completar($this->admin, $this->persona(['nombres' => 'Bea', 'dui' => 'FICTICIO-2']), ['usa_sistema' => true, 'user_id' => $ocupada->id]);
        $p = $this->persona();

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()
            ->assertSee('Crear uno nuevo')->assertSee('anita@icloud.com')->assertDontSee('ocupada@icloud.com');
    }

    public function test_accesos_muestra_la_persona_enlazada_y_a_quien_no_tiene_ficha(): void
    {
        $p = $this->persona();
        $enlazada = $this->cuenta('contabilidad');
        $suelta = $this->cuenta('contabilidad', 'Suelta', 'suelta@icloud.com');
        app(AltaEmpleado::class)->completar($this->admin, $p, ['usa_sistema' => true, 'user_id' => $enlazada->id]);
        $ficha = route('personal.empleados.show', 'p'.$p->id);

        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee($ficha, false)->assertSee('Abrir ficha')->assertSee('Sin ficha en Personal');
        // Quien tiene ficha se ve en su ficha.
        $this->actingAs($this->admin)->get(route('personal.accesos.show', $enlazada))->assertRedirect($ficha);
        $this->actingAs($this->admin)->get(route('personal.accesos.show', $suelta))->assertOk()
            ->assertSee('Sin ficha en Personal')->assertSee('Agregar acceso o módulos');
    }

    /** Enlazar una cuenta con su persona se hace en un solo lugar: la ficha. */
    public function test_enlazar_desde_la_cuenta_ya_no_existe(): void
    {
        $p = $this->persona();
        $cuenta = $this->cuenta('vendedor');

        $this->actingAs($this->admin)->post('/usuarios/'.$cuenta->id.'/personal', ['planilla_empleado_id' => $p->id])->assertNotFound();
        $this->actingAs($this->admin)->get(route('personal.accesos.show', $cuenta))->assertOk()->assertDontSee('planilla_empleado_id');
        $this->assertNull($p->fresh()->user_id);
    }

    public function test_un_solo_nombre_entre_personal_y_su_acceso(): void
    {
        $p = $this->persona();
        $cuenta = $this->cuenta('contabilidad');
        app(AltaEmpleado::class)->completar($this->admin, $p, ['usa_sistema' => true, 'user_id' => $cuenta->id]);
        $ficha = route('personal.empleados.show', 'p'.$p->id);

        // Corregido en Personal, llega al usuario.
        $this->actingAs($this->admin)->post(route('planilla.empleados.datos', $p), [
            'nombre' => 'Ana María Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Ana María Ficticia', $cuenta->fresh()->name);

        // En «Acceso y módulos» no se escribe el nombre, y si llega uno no se toma.
        $this->actingAs($this->admin)->get($ficha)->assertOk()
            ->assertSee('Cambiar rol o correo')->assertDontSee('id="acceso_name"', false);
        $this->actingAs($this->admin)->put(route('personal.accesos.update', $cuenta), [
            'name' => 'Otro Nombre', 'email' => 'anita@icloud.com', 'rol' => 'contabilidad',
        ])->assertRedirect($ficha);
        $this->assertSame('Ana María Ficticia', $cuenta->fresh()->name);
    }

    /** Accesos › Nuevo ya no da acceso a una persona del personal: eso es de su ficha. */
    public function test_accesos_nuevo_no_carga_otra_vez_a_una_persona(): void
    {
        $p = $this->persona();
        $usuarios = User::count();
        $datos = ['rol' => 'vendedor', 'email' => 'ana@icloud.com', 'password' => 'Clave-Ficticia-2026', 'password_confirmation' => 'Clave-Ficticia-2026'];

        $this->actingAs($this->admin)->get(route('personal.accesos.create'))->assertOk()
            ->assertDontSee('Ana Ficticia')->assertSee(route('personal.empleados'), false);
        // Con Personal solo sale una cuenta externa, pedida a propósito y solo con Contabilidad.
        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $datos + ['planilla_empleado_id' => $p->id, 'name' => 'Otra'])
            ->assertSessionHasErrors('cuenta_externa');
        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $datos + ['cuenta_externa' => '1', 'name' => 'Otra'])
            ->assertSessionHasErrors('rol');
        // Ni como externa con el nombre de la persona.
        $this->actingAs($this->admin)->post(route('personal.accesos.store'), array_replace($datos, ['rol' => 'contabilidad']) + ['cuenta_externa' => '1', 'name' => 'Ana Ficticia'])
            ->assertSessionHasErrors('name');
        $this->assertSame($usuarios, User::count());
        $this->assertNull($p->fresh()->user_id);
    }

    /** En la ficha el acceso se da una sola vez: la segunda vez no hay nada que agregar. */
    public function test_la_ficha_no_da_dos_accesos_a_la_misma_persona(): void
    {
        $p = $this->persona();
        $usuarios = User::count();
        $datos = ['usa_sistema' => '1', 'rol' => 'contabilidad', 'email' => 'ana@icloud.com', 'password' => 'Clave-Ficticia-2026', 'password_confirmation' => 'Clave-Ficticia-2026'];

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), $datos)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), array_replace($datos, ['email' => 'otra@icloud.com']))
            ->assertSessionHasErrors('modulos');

        $this->assertSame($usuarios + 1, User::count());
        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()
            ->assertDontSee('Darle acceso al sistema')->assertSee('Cambiar rol o correo');
    }

    public function test_accesos_marca_cuantos_faltan_y_los_filtra(): void
    {
        $p = $this->persona();
        $enlazada = $this->cuenta('contabilidad', 'Enlazada', 'enlazada@icloud.com');
        app(AltaEmpleado::class)->completar($this->admin, $p, ['usa_sistema' => true, 'user_id' => $enlazada->id]);
        $this->cuenta('contabilidad', 'Suelta', 'suelta@icloud.com');

        // El administrador y la suelta siguen entrando, pero se avisa.
        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee('Hay 2 usuarios sin ficha en Personal.')->assertSee('enlazada@icloud.com');
        $this->actingAs($this->admin)->get(route('personal.accesos.index', ['sin_ficha' => 1]))->assertOk()
            ->assertSee('suelta@icloud.com')->assertDontSee('enlazada@icloud.com');
    }

    /** Personal funciona con Planilla apagada (08/11/2026): Accesos sigue avisando quién no tiene ficha. */
    public function test_con_planilla_apagada_accesos_sigue_con_personal(): void
    {
        config(['planilla.enabled' => false]);
        $this->cuenta('contabilidad');

        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee('Sin ficha en Personal')->assertSee('Nueva cuenta externa');
        // Aun sin Planilla, acá solo se crea la cuenta externa: el empleado entra desde su ficha.
        $this->actingAs($this->admin)->get(route('personal.accesos.create'))->assertOk()
            ->assertSee('id="name"', false)->assertDontSee('value="administrador"', false)->assertDontSee('planilla_empleado_id');
    }
}
