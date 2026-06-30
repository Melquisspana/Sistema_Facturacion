<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\UsuarioAdminInicialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuarioAdminInicialSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'admin@dulceslanegrita.test';

    private const PASSWORD = 'Admin#2026Temporal';

    public function test_crea_el_usuario_admin(): void
    {
        $this->seed(UsuarioAdminInicialSeeder::class);

        $admin = User::where('email', self::EMAIL)->first();
        $this->assertNotNull($admin);
        $this->assertSame('Administrador', $admin->name);
        $this->assertTrue((bool) $admin->activo);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
    }

    public function test_asigna_rol_administrador(): void
    {
        $this->seed(UsuarioAdminInicialSeeder::class);

        $admin = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertTrue($admin->hasRole('administrador'));
    }

    public function test_es_idempotente_y_no_duplica(): void
    {
        $this->seed(UsuarioAdminInicialSeeder::class);
        $this->seed(UsuarioAdminInicialSeeder::class);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $admin = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(1, $admin->roles()->count());
    }

    public function test_no_cambia_la_contrasena_de_un_usuario_existente(): void
    {
        // Usuario admin preexistente con OTRA contraseña.
        $existente = User::factory()->create([
            'email' => self::EMAIL,
            'password' => Hash::make('OtraClaveDistinta1!'),
        ]);

        $this->seed(UsuarioAdminInicialSeeder::class);

        $existente->refresh();
        $this->assertTrue(Hash::check('OtraClaveDistinta1!', $existente->password), 'No debe pisar la contraseña existente.');
        $this->assertFalse(Hash::check(self::PASSWORD, $existente->password));
        // Pero sí le asegura el rol administrador.
        $this->assertTrue($existente->hasRole('administrador'));
    }
}
