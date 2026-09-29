<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FuncionPersonalRuta;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El catálogo del personal de campo: quién sale a vender, repartir o cobrar.
 */
class PersonalRutaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    public function test_se_da_de_alta_una_persona_con_varias_funciones(): void
    {
        $this->actingAs($this->admin())
            ->post(route('rutas.personal.store'), [
                'nombre' => 'Rene Barillas',
                'telefono' => '7777-0000',
                'funciones' => [FuncionPersonalRuta::Vendedor->value, FuncionPersonalRuta::Cobrador->value],
            ])
            ->assertRedirect();

        $persona = PersonalRuta::sole();

        $this->assertSame('Rene Barillas', $persona->nombre);
        $this->assertTrue($persona->activo);
        // Las funciones son combinables y van normalizadas: se pueden consultar.
        $this->assertCount(2, $persona->funcionesEnum());
        $this->assertTrue($persona->tieneFuncion(FuncionPersonalRuta::Vendedor));
        $this->assertFalse($persona->puedeSerResponsable());
        // Sin login: es lo normal en el personal de campo.
        $this->assertNull($persona->user_id);
    }

    public function test_una_persona_no_queda_atada_a_ninguna_ruta_ni_cliente(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);

        // El catálogo no tiene columna de ruta, cliente ni zona: cualquiera puede ir a
        // cualquier lado, y una columna así se volvería una regla que nadie cumple.
        $this->assertFalse(Schema::hasColumn('rutas_personal', 'ruta_id'));
        $this->assertFalse(Schema::hasColumn('rutas_personal', 'cliente_id'));

        $sanMiguel = Ruta::create(['nombre' => 'San Miguel']);
        $sonsonate = Ruta::create(['nombre' => 'Sonsonate']);

        foreach ([$sanMiguel, $sonsonate] as $ruta) {
            $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::Planificada]);
            app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], null);
        }

        $this->assertSame(2, $persona->salidas()->count());
    }

    public function test_una_persona_no_se_borra_se_desactiva(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);

        // No existe ruta de borrado: alguien que ya fue en salidas no puede desaparecer.
        $this->assertFalse(Route::has('rutas.personal.destroy'));

        $this->actingAs($this->admin())
            ->patch(route('rutas.personal.toggle-activo', $persona))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertFalse($persona->refresh()->activo);
        $this->assertSame(1, PersonalRuta::count());
    }

    /**
     * La ficha se renderiza de verdad porque pinta insignias de función y de rol, y una
     * clase mal puesta ahí no la atrapa ninguna prueba de servicio.
     */
    public function test_la_ficha_muestra_sus_funciones_y_sus_salidas(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);

        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);
        $persona->funciones()->create(['funcion' => FuncionPersonalRuta::Vendedor->value]);
        $persona->funciones()->create(['funcion' => FuncionPersonalRuta::ResponsableSalida->value]);

        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], $persona->id);

        $this->actingAs($this->admin())
            ->get(route('rutas.personal.show', $persona))
            ->assertOk()
            ->assertSee('Rene Barillas')
            ->assertSee(FuncionPersonalRuta::Vendedor->label())
            ->assertSee(FuncionPersonalRuta::ResponsableSalida->label())
            ->assertSee('San Miguel')
            ->assertDontSee('Documentos físicos en su poder');
    }

    public function test_la_pantalla_de_editar_trae_marcadas_las_funciones_que_ya_tiene(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas', 'telefono' => '7777-0000']);
        $persona->funciones()->create(['funcion' => FuncionPersonalRuta::Cobrador->value]);

        $this->actingAs($this->admin())
            ->get(route('rutas.personal.edit', $persona))
            ->assertOk()
            ->assertSee('Rene Barillas')
            ->assertSee('7777-0000')
            // Todas las funciones se ofrecen; ninguna es un cargo excluyente.
            ->assertSee(FuncionPersonalRuta::Cobrador->label())
            ->assertSee(FuncionPersonalRuta::Repartidor->label());
    }

    public function test_un_usuario_no_puede_enlazarse_a_dos_personas(): void
    {
        $usuario = User::factory()->create(['activo' => true]);
        PersonalRuta::create(['nombre' => 'Rene Barillas', 'user_id' => $usuario->id]);

        $this->actingAs($this->admin())
            ->post(route('rutas.personal.store'), ['nombre' => 'Otro Rene', 'user_id' => $usuario->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, PersonalRuta::count());
    }

    public function test_ver_personal_exige_permiso(): void
    {
        $sinPermiso = User::factory()->create();
        $sinPermiso->givePermissionTo(['rutas.ver']);

        $this->actingAs($sinPermiso)->get(route('rutas.personal.index'))->assertForbidden();

        $conPermiso = User::factory()->create();
        $conPermiso->givePermissionTo(['rutas.ver', 'rutas.personal.ver']);

        $this->actingAs($conPermiso)->get(route('rutas.personal.index'))->assertOk();

        // Ver no es gestionar: el alta necesita el otro permiso.
        $this->actingAs($conPermiso)->get(route('rutas.personal.create'))->assertForbidden();
    }
}
