<?php

namespace Tests\Feature\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Models\PersonalRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Crear un usuario con rol Vendedor deja su ficha de Rutas lista y enlazada: no se carga en
 * otro lado. Cambiar el rol o desactivarlo desactiva la ficha, sin borrarla.
 */
class FichaVendedorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'vendedor'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function crear(string $nombre, string $rol = 'vendedor', string $email = 'vendedor@ejemplo.test'): User
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => $nombre, 'email' => $email,
            'password' => 'Str0ng#Passw0rd!', 'password_confirmation' => 'Str0ng#Passw0rd!',
            'rol' => $rol, 'activo' => '1',
        ])->assertRedirect();

        return User::where('email', $email)->sole();
    }

    public function test_crear_un_vendedor_le_arma_la_ficha_enlazada_con_funcion_vendedor(): void
    {
        $usuario = $this->crear('Pedro Pérez');

        $ficha = PersonalRuta::where('user_id', $usuario->id)->sole();
        $this->assertSame('Pedro Pérez', $ficha->nombre);
        $this->assertTrue($ficha->activo);
        $this->assertTrue($ficha->tieneFuncion(FuncionPersonalRuta::Vendedor));
    }

    public function test_engancha_la_ficha_cargada_solo_con_el_primer_nombre(): void
    {
        $existente = PersonalRuta::create(['nombre' => 'Marcelo']);

        $usuario = $this->crear('Marcelo Rene Villacorta');

        $this->assertSame($usuario->id, $existente->fresh()->user_id);
        $this->assertSame(1, PersonalRuta::count());
    }

    public function test_con_dos_fichas_posibles_no_adivina_y_crea_una_nueva(): void
    {
        PersonalRuta::create(['nombre' => 'José']);
        PersonalRuta::create(['nombre' => 'jose']);

        $usuario = $this->crear('José Ramírez');

        $this->assertSame(1, PersonalRuta::where('user_id', $usuario->id)->count());
        $this->assertSame(2, PersonalRuta::whereNull('user_id')->count());
    }

    public function test_otro_rol_no_crea_ficha(): void
    {
        $this->crear('Ana Oficina', 'facturacion', 'ana@ejemplo.test');

        $this->assertSame(0, PersonalRuta::count());
    }

    public function test_desactivar_o_cambiar_de_rol_desactiva_la_ficha_sin_borrarla(): void
    {
        $usuario = $this->crear('Pedro Pérez');
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('usuarios.toggle-activo', $usuario))->assertRedirect();
        $this->assertFalse(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);

        $this->actingAs($admin)->patch(route('usuarios.toggle-activo', $usuario->fresh()))->assertRedirect();
        $this->assertTrue(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);

        $this->actingAs($admin)->put(route('usuarios.update', $usuario), [
            'name' => 'Pedro Pérez', 'email' => 'vendedor@ejemplo.test', 'rol' => 'facturacion', 'activo' => '1',
        ])->assertRedirect();
        $this->assertFalse(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);
    }
}
