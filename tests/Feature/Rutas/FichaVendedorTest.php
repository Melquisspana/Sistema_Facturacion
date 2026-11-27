<?php

namespace Tests\Feature\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Models\PersonalRuta;
use App\Models\User;
use App\Services\Rutas\FichaVendedor;
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
        $usuario = $this->crear('Pedro Prueba');

        $ficha = PersonalRuta::where('user_id', $usuario->id)->sole();
        $this->assertSame('Pedro Prueba', $ficha->nombre);
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

        $usuario = $this->crear('José Ficticio');

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
        $usuario = $this->crear('Pedro Prueba');
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('personal.accesos.toggle-activo', $usuario))->assertRedirect();
        $this->assertFalse(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);

        $this->actingAs($admin)->patch(route('personal.accesos.toggle-activo', $usuario->fresh()))->assertRedirect();
        $this->assertTrue(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);

        $this->actingAs($admin)->put(route('personal.accesos.update', $usuario), [
            'name' => 'Pedro Prueba', 'email' => 'vendedor@ejemplo.test', 'rol' => 'facturacion', 'activo' => '1',
        ])->assertRedirect();
        $this->assertFalse(PersonalRuta::where('user_id', $usuario->id)->sole()->activo);
    }

    /**
     * «Jefatura saca pedido pero no entrega» (Melqui, 26/11/2026): tiene ficha para sacar
     * pedidos, pero sin la función Vendedor. El vendedor y el administrador la conservan y son
     * los que figuran como quien entrega.
     */
    public function test_jefatura_tiene_ficha_sin_funcion_vendedor_y_el_vendedor_y_el_administrador_la_conservan(): void
    {
        $vendedor = $this->crear('Pedro Prueba');
        $jefa = $this->crear('Jefa Prueba', 'jefatura', 'jefa@ejemplo.test');
        $admin = $this->admin();
        app(FichaVendedor::class)->asegurar($admin);

        $fichaJefa = PersonalRuta::where('user_id', $jefa->id)->sole();
        $this->assertTrue($fichaJefa->activo);
        $this->assertFalse($fichaJefa->tieneFuncion(FuncionPersonalRuta::Vendedor));
        $fichas = [PersonalRuta::where('user_id', $vendedor->id)->sole(), PersonalRuta::where('user_id', $admin->id)->sole()];
        foreach ($fichas as $ficha) {
            $this->assertTrue($ficha->tieneFuncion(FuncionPersonalRuta::Vendedor));
        }
        $this->assertEqualsCanonicalizing(array_map(fn (PersonalRuta $f) => $f->id, $fichas), PersonalRuta::query()->puedenEntregar(null)->pluck('id')->all());
    }

    public function test_pasar_a_jefatura_le_quita_la_funcion_vendedor_y_volver_a_vendedor_se_la_devuelve(): void
    {
        $usuario = $this->crear('Pedro Prueba');
        $admin = $this->admin();
        $cambiarRol = fn (string $rol) => $this->actingAs($admin)->put(route('personal.accesos.update', $usuario), [
            'name' => 'Pedro Prueba', 'email' => 'vendedor@ejemplo.test', 'rol' => $rol, 'activo' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $cambiarRol('jefatura');
        $ficha = PersonalRuta::where('user_id', $usuario->id)->sole();
        $this->assertTrue($ficha->activo);
        $this->assertFalse($ficha->tieneFuncion(FuncionPersonalRuta::Vendedor));

        $cambiarRol('vendedor');
        $this->assertTrue($ficha->fresh()->tieneFuncion(FuncionPersonalRuta::Vendedor));
    }

    /**
     * La ficha de Jefatura armada antes del arreglo trae la función Vendedor: se le quita la
     * próxima vez que entra, sin crear otra. La función que la oficina le puso a mano, no.
     */
    public function test_la_ficha_de_jefatura_que_ya_tenia_la_funcion_vendedor_la_pierde_al_entrar(): void
    {
        $jefa = User::factory()->create(['activo' => true])->assignRole('jefatura');
        $ficha = PersonalRuta::create(['nombre' => 'Jefa Prueba', 'user_id' => $jefa->id]);
        $ficha->funciones()->create(['funcion' => FuncionPersonalRuta::Vendedor->value]);
        $ficha->funciones()->create(['funcion' => FuncionPersonalRuta::Cobrador->value]);

        app(FichaVendedor::class)->asegurar($jefa);

        $this->assertSame(1, PersonalRuta::count());
        $this->assertEquals([FuncionPersonalRuta::Cobrador], $ficha->fresh()->funcionesEnum()->all());
        $this->assertFalse(PersonalRuta::query()->puedenEntregar(null)->whereKey($ficha->id)->exists());
    }
}
