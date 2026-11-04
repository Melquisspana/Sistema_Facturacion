<?php

namespace Tests\Feature\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Models\PersonalRuta;
use App\Models\User;
use App\Services\Usuarios\CrearUsuario;
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

    /** El acceso de un empleado se da en su ficha; acá basta el servicio que usa la ficha. */
    private function crear(string $nombre, string $rol = 'vendedor', string $email = 'vendedor@ejemplo.test'): User
    {
        return app(CrearUsuario::class)->crear([
            'name' => $nombre, 'email' => $email, 'password' => 'Str0ng#Passw0rd!', 'activo' => true, 'rol' => $rol,
        ]);
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
        $existente = PersonalRuta::create(['nombre' => 'Hernán']);

        $usuario = $this->crear('Hernán Ernesto Prueba');

        $this->assertSame($usuario->id, $existente->fresh()->user_id);
        $this->assertSame(1, PersonalRuta::count());
        // Un solo nombre: la ficha enganchada toma el del usuario.
        $this->assertSame('Hernán Ernesto Prueba', $existente->fresh()->nombre);
    }

    public function test_por_el_primer_nombre_no_engancha_si_otra_persona_lo_comparte(): void
    {
        $existente = PersonalRuta::create(['nombre' => 'Hernán']);
        User::factory()->create(['name' => 'Hernán Gómez Inventado']);

        $usuario = $this->crear('Hernán Ernesto Prueba');

        $this->assertNull($existente->fresh()->user_id);
        $this->assertSame('Hernán', $existente->fresh()->nombre);
        $this->assertSame('Hernán Ernesto Prueba', PersonalRuta::where('user_id', $usuario->id)->sole()->nombre);
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

        $this->actingAs($admin)->patch(route('personal.accesos.toggle-activo', $usuario))->assertRedirect();
        $this->assertFalse(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);

        $this->actingAs($admin)->patch(route('personal.accesos.toggle-activo', $usuario->fresh()))->assertRedirect();
        $this->assertTrue(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);

        $this->actingAs($admin)->put(route('personal.accesos.update', $usuario), [
            'name' => 'Pedro Pérez', 'email' => 'vendedor@ejemplo.test', 'rol' => 'facturacion', 'activo' => '1',
        ])->assertRedirect();
        $this->assertFalse(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);
    }
}
