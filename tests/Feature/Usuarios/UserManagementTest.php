<?php

namespace Tests\Feature\Usuarios;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
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
        $this->get(route('usuarios.index'))->assertRedirect('/login');
    }

    public function test_no_admin_no_entra(): void
    {
        $u = User::factory()->create()->assignRole('facturacion');
        $this->actingAs($u)->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_registro_publico_sigue_desactivado(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_admin_crea_usuario_con_rol(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('usuarios.store'), $this->datosUsuario([
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
        $otro = User::factory()->create()->assignRole('consulta');

        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk()->assertSee('Nuevo usuario');
        $this->actingAs($admin)->get(route('usuarios.create'))->assertOk()->assertSee('Rol');
        $this->actingAs($admin)->get(route('usuarios.show', $otro))->assertOk();
        $this->actingAs($admin)->get(route('usuarios.edit', $otro))->assertOk();
        $this->actingAs($admin)->get(route('usuarios.password.edit', $otro))->assertOk()->assertSee('Nueva contraseña');
    }

    public function test_password_debil_es_rechazado(): void
    {
        $this->actingAs($this->admin())
            ->post(route('usuarios.store'), $this->datosUsuario(['password' => 'abc', 'password_confirmation' => 'abc']))
            ->assertSessionHasErrors('password');
    }

    public function test_no_puede_eliminarse_a_si_mismo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->delete(route('usuarios.destroy', $admin))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_no_inactiva_al_ultimo_admin(): void
    {
        $admin = $this->admin(); // único administrador activo

        $this->actingAs($admin)->patch(route('usuarios.toggle-activo', $admin));

        $this->assertTrue($admin->fresh()->activo, 'El último admin no debe poder inactivarse.');
    }

    public function test_no_quita_rol_admin_al_ultimo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('usuarios.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'rol' => 'consulta',
            'activo' => '1',
        ]);

        $this->assertTrue($admin->fresh()->hasRole('administrador'), 'El último admin no debe perder el rol.');
    }

    public function test_con_dos_admins_si_puede_inactivar_uno(): void
    {
        $admin1 = $this->admin();
        $admin2 = User::factory()->create(['activo' => true])->assignRole('administrador');

        $this->actingAs($admin1)->patch(route('usuarios.toggle-activo', $admin2));

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
        $usuario = User::factory()->create()->assignRole('consulta');

        $this->actingAs($admin)->put(route('usuarios.password.update', $usuario), [
            'password' => 'Nuev0#Passw0rd!',
            'password_confirmation' => 'Nuev0#Passw0rd!',
        ])->assertRedirect(route('usuarios.show', $usuario));

        $this->assertTrue(Hash::check('Nuev0#Passw0rd!', $usuario->fresh()->password));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'usuario',
            'subject_id' => $usuario->id,
            'description' => 'cambió la contraseña',
        ]);
    }
}
