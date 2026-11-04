<?php

namespace Tests\Feature\Usuarios;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La gestión de usuarios vive en Personal › Accesos (antes, el módulo «Usuarios»). Acá, con
 * Planilla apagada (como en una instalación nueva): es el único lugar para crear un usuario.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function datosUsuario(array $override = []): array
    {
        return array_merge([
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@dulces.test',
            'password' => 'Str0ng#Passw0rd!',
            'password_confirmation' => 'Str0ng#Passw0rd!',
            'rol' => 'facturacion',
            'activo' => '1',
        ], $override);
    }

    public function test_invitado_es_redirigido(): void
    {
        $this->get(route('personal.accesos.index'))->assertRedirect('/login');
        $this->get('/usuarios')->assertRedirect('/login');
    }

    public function test_no_admin_no_entra(): void
    {
        $u = User::factory()->create()->assignRole('facturacion');
        $this->actingAs($u)->get(route('personal.accesos.index'))->assertForbidden();
        $this->actingAs($u)->get('/usuarios')->assertForbidden();
    }

    public function test_registro_publico_sigue_desactivado(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_admin_crea_usuario_con_rol(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('personal.accesos.store'), $this->datosUsuario([
            'email' => 'facturador@dulces.test',
        ]))->assertRedirect();

        $usuario = User::where('email', 'facturador@dulces.test')->firstOrFail();
        $this->assertTrue($usuario->hasRole('facturacion'));
        $this->assertTrue(Hash::check('Str0ng#Passw0rd!', $usuario->password));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'usuario',
            'subject_id' => $usuario->id,
            'description' => 'asignó el rol facturacion',
        ]);
    }

    public function test_admin_puede_ver_todas_las_pantallas(): void
    {
        $admin = $this->admin();
        $otro = User::factory()->create()->assignRole('jefatura');

        $this->actingAs($admin)->get(route('personal.accesos.index'))->assertOk()->assertSee('Nuevo usuario');
        $this->actingAs($admin)->get(route('personal.accesos.create'))->assertOk()->assertSee('Rol');
        $this->actingAs($admin)->get(route('personal.accesos.show', $otro))->assertOk()
            ->assertSee('Cambiar rol o correo')->assertSee('Cambiar contraseña')->assertSee('Desactivar acceso')
            ->assertSee('Historial del acceso')->assertSee('Eliminar cuenta');
    }

    /** Los favoritos de Usuarios siguen sirviendo; sus formularios viejos ya no existen. */
    public function test_las_direcciones_viejas_de_usuarios_llevan_al_lugar_nuevo(): void
    {
        $admin = $this->admin();
        $otro = User::factory()->create()->assignRole('jefatura');

        $this->actingAs($admin)->get('/usuarios')->assertStatus(301)->assertRedirect(route('personal.accesos.index'));
        $this->actingAs($admin)->get('/usuarios/create')->assertStatus(301)->assertRedirect(route('personal.accesos.create'));
        foreach (["/usuarios/{$otro->id}", "/usuarios/{$otro->id}/edit", "/usuarios/{$otro->id}/password"] as $vieja) {
            $this->actingAs($admin)->get($vieja)->assertStatus(301)->assertRedirect(route('personal.accesos.show', $otro));
        }

        $this->actingAs($admin)->post('/usuarios', $this->datosUsuario())->assertStatus(405);
        $this->actingAs($admin)->put("/usuarios/{$otro->id}", ['rol' => 'administrador'])->assertStatus(405);
        $this->actingAs($admin)->delete("/usuarios/{$otro->id}")->assertStatus(405);
        $this->actingAs($admin)->patch("/usuarios/{$otro->id}/toggle-activo")->assertNotFound();
        $this->assertSame(0, User::where('email', 'nuevo@dulces.test')->count());
        $this->assertTrue($otro->fresh()->hasRole('jefatura'));
    }

    public function test_password_debil_es_rechazado(): void
    {
        $this->actingAs($this->admin())
            ->post(route('personal.accesos.store'), $this->datosUsuario(['password' => 'abc', 'password_confirmation' => 'abc']))
            ->assertSessionHasErrors('password');
    }

    public function test_no_puede_eliminarse_a_si_mismo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->delete(route('personal.accesos.destroy', $admin))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_no_inactiva_al_ultimo_admin(): void
    {
        $admin = $this->admin(); // único administrador activo

        $this->actingAs($admin)->patch(route('personal.accesos.toggle-activo', $admin))
            ->assertSessionHasErrorsIn('acceso', 'activo');

        $this->assertTrue($admin->fresh()->activo, 'El último admin no debe poder inactivarse.');
    }

    public function test_no_quita_rol_admin_al_ultimo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('personal.accesos.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'rol' => 'jefatura',
        ])->assertSessionHasErrorsIn('acceso', 'rol');

        $this->assertTrue($admin->fresh()->hasRole('administrador'), 'El último admin no debe perder el rol.');
    }

    public function test_con_dos_admins_si_puede_inactivar_uno(): void
    {
        $admin1 = $this->admin();
        $admin2 = User::factory()->create(['activo' => true])->assignRole('administrador');

        $this->actingAs($admin1)->patch(route('personal.accesos.toggle-activo', $admin2))
            ->assertRedirect(route('personal.accesos.show', $admin2));

        $this->assertFalse($admin2->fresh()->activo);
    }

    public function test_usuario_inactivo_no_puede_iniciar_sesion(): void
    {
        User::factory()->create([
            'email' => 'inactivo@dulces.test',
            'password' => Hash::make('Str0ng#Passw0rd!'),
            'activo' => false,
        ])->assignRole('facturacion');

        $this->post('/login', [
            'email' => 'inactivo@dulces.test',
            'password' => 'Str0ng#Passw0rd!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_admin_cambia_password(): void
    {
        $admin = $this->admin();
        $usuario = User::factory()->create()->assignRole('jefatura');

        $this->actingAs($admin)->put(route('personal.accesos.password', $usuario), [
            'password' => 'Nuev0#Passw0rd!',
            'password_confirmation' => 'Nuev0#Passw0rd!',
        ])->assertRedirect(route('personal.accesos.show', $usuario));

        $this->assertTrue(Hash::check('Nuev0#Passw0rd!', $usuario->fresh()->password));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'usuario',
            'subject_id' => $usuario->id,
            'description' => 'cambió la contraseña',
        ]);

        // Débil o sin repetir: no cambia, y el error va al bloque del acceso.
        $this->actingAs($admin)->put(route('personal.accesos.password', $usuario), [
            'password' => 'abc', 'password_confirmation' => 'abc',
        ])->assertSessionHasErrorsIn('acceso', 'password');
        $this->assertTrue(Hash::check('Nuev0#Passw0rd!', $usuario->fresh()->password));
    }

    public function test_cambiar_correo_valida_que_sea_unico(): void
    {
        $admin = $this->admin();
        $usuario = User::factory()->create(['email' => 'uno@dulces.test'])->assignRole('jefatura');

        $this->actingAs($admin)->put(route('personal.accesos.update', $usuario), [
            'name' => $usuario->name, 'email' => $admin->email, 'rol' => 'jefatura',
        ])->assertSessionHasErrorsIn('acceso', 'email');
        $this->actingAs($admin)->put(route('personal.accesos.update', $usuario), [
            'name' => $usuario->name, 'email' => 'uno@dulces.test', 'rol' => 'inventado',
        ])->assertSessionHasErrorsIn('acceso', 'rol');
        $this->actingAs($admin)->put(route('personal.accesos.update', $usuario), [
            'name' => '', 'email' => 'uno@dulces.test', 'rol' => 'jefatura',
        ])->assertSessionHasErrorsIn('acceso', 'name');

        $this->assertSame('uno@dulces.test', $usuario->fresh()->email);
    }
}
