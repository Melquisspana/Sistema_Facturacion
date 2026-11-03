<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\PersonalRuta;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un solo nombre por persona: quien está en Personal no cambia su nombre desde Rutas,
 * porque volvería a haber dos. Quien solo existe en Rutas sigue editándolo ahí.
 */
class RutasNombreDesdePersonalTest extends TestCase
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
        config(['planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->admin = User::factory()->create(['activo' => true]);
        $this->admin->syncPermissions(PermisoSistema::todos());
    }

    public function test_quien_esta_en_personal_no_cambia_el_nombre_desde_rutas(): void
    {
        $empleado = app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sale_rutas' => true,
        ]);
        $ruta = $empleado->personalRuta;

        $this->actingAs($this->admin)->get(route('rutas.personal.edit', $ruta))->assertOk()
            ->assertSee('Se edita en')
            ->assertSee(route('personal.empleados.show', 'p'.$empleado->id), false)
            ->assertDontSee('name="nombre"', false);

        $this->actingAs($this->admin)->put(route('rutas.personal.update', $ruta), [
            'nombre' => 'Otro Nombre', 'telefono' => '0000-0000', 'funciones' => ['vendedor'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Ana Ficticia', $ruta->fresh()->nombre);
        $this->assertSame('0000-0000', $ruta->fresh()->telefono);
    }

    public function test_quien_solo_esta_en_rutas_sigue_editando_su_nombre(): void
    {
        $ruta = PersonalRuta::create(['nombre' => 'Bea', 'activo' => true]);

        $this->actingAs($this->admin)->get(route('rutas.personal.edit', $ruta))->assertOk()
            ->assertSee('name="nombre"', false);

        $this->actingAs($this->admin)->put(route('rutas.personal.update', $ruta), ['nombre' => 'Bea Ficticia'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Bea Ficticia', $ruta->fresh()->nombre);
    }
}
