<?php

namespace Tests\Feature\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Enums\PermisoSistema;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PersonalRutaEnFichaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        config(['planilla.enabled' => true, 'gastos.enabled' => false]);
        $this->admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->admin->givePermissionTo(PermisoSistema::todos());
    }

    private function ficha(PersonalRuta $persona): PlanillaEmpleado
    {
        return PlanillaEmpleado::create([
            'clave' => 'ruta-'.$persona->id,
            'registrado_por' => $this->admin->id,
            'nombre' => $persona->nombre, 'personal_ruta_id' => $persona->id,
            'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'activo' => true,
        ]);
    }

    public function test_administrador_usa_personal_y_conserva_acceso_a_huerfanos(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Ana Ficticia']);
        $ficha = $this->ficha($persona);
        $huerfano = PersonalRuta::create(['nombre' => 'Bea Ficticia']);
        $this->actingAs($this->admin);
        $this->get(route('rutas.dashboard'))->assertOk()->assertDontSee('href="'.route('rutas.personal.index').'"', false);
        $this->get(route('rutas.personal.index'))->assertRedirect(route('personal.empleados'));
        $this->get(route('rutas.personal.create'))->assertRedirect(route('planilla.empleados.create'));
        foreach (['show', 'edit'] as $accion) {
            $this->get(route('rutas.personal.'.$accion, $persona))->assertRedirect(route('personal.empleados.show', 'p'.$ficha->id));
            $this->get(route('rutas.personal.'.$accion, $huerfano))->assertOk();
        }
    }

    public function test_sin_planilla_ver_no_entra_y_con_planilla_apagada_sigue_en_personal(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Ana Ficticia']);
        $this->ficha($persona);
        // Sin planilla.ver no hay fichas de empleados, tampoco las de Rutas (04/11/2026).
        foreach (['jefatura', 'facturacion'] as $rol) {
            $usuario = User::factory()->create(['activo' => true])->assignRole($rol);
            $this->assertFalse($usuario->can('planilla.ver'));
            $this->actingAs($usuario)->get(route('rutas.personal.index'))->assertForbidden();
            $this->get(route('rutas.personal.show', $persona))->assertForbidden();
        }
        // Con Planilla apagada, Personal sigue (08/11/2026): Rutas no vuelve a crear gente suelta.
        config(['planilla.enabled' => false]);
        $this->actingAs($this->admin)->get(route('rutas.personal.index'))->assertRedirect(route('personal.empleados'));
        $this->get(route('rutas.personal.create'))->assertRedirect(route('planilla.empleados.create'));
        $this->assertTrue(PersonalRuta::seGestionaEnPersonal($this->admin));
        $this->assertFalse(PersonalRuta::seGestionaEnPersonal(null));
    }

    public function test_ficha_muestra_y_guarda_datos_y_valida_funciones(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Ana Ficticia', 'telefono' => '7777-0000', 'notas' => 'Nota de rutas']);
        $persona->funciones()->create(['funcion' => FuncionPersonalRuta::Cobrador->value]);
        $ficha = $this->ficha($persona);
        $url = route('personal.empleados.show', 'p'.$ficha->id);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('7777-0000')->assertSee('Nota de rutas')
            ->assertSee('Editar datos de rutas')->assertDontSee('Abrir en Rutas');
        $this->put(route('rutas.personal.update', $persona), [
            'telefono' => '7000-1234', 'notas' => 'Nueva nota', 'funciones' => ['vendedor', 'repartidor'],
        ])->assertRedirect($url)->assertSessionHas('status', 'Datos actualizados.')->assertSessionHasNoErrors();
        $this->assertSame('7000-1234', $persona->fresh()->telefono);
        $this->assertEqualsCanonicalizing(['vendedor', 'repartidor'], $persona->fresh()->funcionesEnum()->map(fn ($f) => $f->value)->all());
        $this->from($url)->put(route('rutas.personal.update', $persona), ['funciones' => ['invalida']])->assertSessionHasErrors('funciones.0');
        $this->assertSame('7000-1234', $persona->fresh()->telefono);
    }

    public function test_sin_gestionar_no_ofrece_formulario_ni_permite_guardar(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Ana Ficticia']);
        $ficha = $this->ficha($persona);
        $lector = User::factory()->create(['activo' => true]);
        $lector->givePermissionTo(['planilla.ver', 'rutas.ver', 'rutas.personal.ver']);
        $this->actingAs($lector)->get(route('personal.empleados.show', 'p'.$ficha->id))->assertOk()->assertDontSee('Editar datos de rutas');
        $this->put(route('rutas.personal.update', $persona), ['telefono' => '7000-1234'])->assertForbidden();
    }

    public function test_aviso_y_alta_preseleccionan_huerfanos_incluso_inactivos(): void
    {
        foreach ([true, false] as $activo) {
            $persona = PersonalRuta::create(['nombre' => 'Persona '.(int) $activo, 'activo' => $activo]);
            $alta = route('planilla.empleados.create', ['personal_ruta_id' => $persona->id]);
            $this->actingAs($this->admin)->get(route('personal.empleados'))->assertOk()->assertSee('Darla de alta')
                ->assertSee($alta, false)->assertSee(route('rutas.personal.show', $persona), false);
            $respuesta = $this->get($alta)->assertOk()->assertViewHas('candidatos', fn ($candidatos) => collect($candidatos)->contains(fn ($c) => $c['tipo'] === 'ruta' && $c['id'] === $persona->id));
            $this->assertMatchesRegularExpression('/<option value="'.$persona->id.'"\s+selected[^>]*>'.preg_quote($persona->nombre, '/').'<\/option>/', $respuesta->getContent());
        }
        $this->get(route('personal.empleados'))->assertSee('2 personas de Rutas todavía no tienen ficha en Personal');
    }
}
