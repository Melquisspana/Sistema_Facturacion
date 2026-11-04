<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La contadora externa no es empleada: tiene cuenta sin ficha en Personal, solo con rol
 * Contabilidad, y no se cuenta entre los usuarios a los que les falta su persona.
 */
class CuentaExternaTest extends TestCase
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

        $this->admin = User::factory()->create(['activo' => true]);
        $this->admin->syncPermissions(PermisoSistema::todos());
    }

    /** @return array<string, string> */
    private function datos(array $cambios = []): array
    {
        return array_replace([
            'cuenta_externa' => '1', 'name' => 'Contadora Externa Ficticia', 'rol' => 'contabilidad',
            'email' => 'externa@example.test', 'password' => 'Clave-Ficticia-2026', 'password_confirmation' => 'Clave-Ficticia-2026',
        ], $cambios);
    }

    public function test_accesos_crea_la_cuenta_externa_sin_ficha_y_sin_aviso(): void
    {
        // Con Personal, acá solo se crea la cuenta externa: no se elige ninguna persona.
        $this->actingAs($this->admin)->get(route('personal.accesos.create'))->assertOk()
            ->assertSee('Nueva cuenta externa')->assertSee('Crear cuenta externa')
            ->assertDontSee('planilla_empleado_id')->assertDontSee('value="administrador"', false);

        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->datos())->assertSessionHasNoErrors();

        $externa = User::where('email', 'externa@example.test')->sole();
        $this->assertTrue($externa->cuenta_externa);
        $this->assertTrue($externa->activo);
        $this->assertTrue($externa->hasRole('contabilidad'));
        $this->assertSame(0, PlanillaEmpleado::count());

        // Solo el administrador (sin ficha) cuenta en el aviso; la externa se ve como tal.
        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee('Hay 1 usuario sin ficha en Personal.')->assertSee('Cuenta externa');
        $this->actingAs($this->admin)->get(route('personal.accesos.index', ['sin_ficha' => 1]))->assertOk()
            ->assertDontSee('externa@example.test');
        // Su página: las mismas acciones que la ficha, con el nombre editable y solo Contabilidad.
        $this->actingAs($this->admin)->get(route('personal.accesos.show', $externa))->assertOk()
            ->assertSee('Cuenta externa: no es empleada')->assertSee('id="acceso_name"', false)
            ->assertSee('Cambiar contraseña')->assertSee('Desactivar acceso')
            ->assertDontSee('<option value="administrador"', false);
    }

    public function test_no_se_carga_como_externa_a_quien_ya_esta_en_personal(): void
    {
        app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Carla', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-9',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->datos(['name' => ' carla ficticia ']))
            ->assertSessionHasErrors('name');
        $this->assertSame(0, User::where('email', 'externa@example.test')->count());
    }

    public function test_la_cuenta_externa_solo_lleva_el_rol_contabilidad(): void
    {
        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->datos(['rol' => 'administrador']))
            ->assertSessionHasErrors('rol');
        $this->assertSame(0, User::where('email', 'externa@example.test')->count());

        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->datos());
        $externa = User::where('email', 'externa@example.test')->sole();
        $this->actingAs($this->admin)->put(route('personal.accesos.update', $externa), [
            'name' => $externa->name, 'email' => $externa->email, 'rol' => 'administrador',
        ])->assertSessionHasErrorsIn('acceso', 'rol');
        $this->assertTrue($externa->fresh()->hasRole('contabilidad'));
    }

    public function test_se_crea_aunque_no_pueda_gestionar_personal_y_mantiene_las_validaciones(): void
    {
        $soloUsuarios = User::factory()->create(['activo' => true]);
        $soloUsuarios->syncPermissions([PermisoSistema::UsuariosGestionar->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($soloUsuarios)->post(route('personal.accesos.store'), $this->datos(['password_confirmation' => 'otra']))
            ->assertSessionHasErrors('password');
        $this->actingAs($soloUsuarios)->post(route('personal.accesos.store'), $this->datos(['name' => '']))
            ->assertSessionHasErrors('name');
        $this->assertSame(0, User::where('email', 'externa@example.test')->count());

        $this->actingAs($soloUsuarios)->post(route('personal.accesos.store'), $this->datos())->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'externa@example.test')->sole()->cuenta_externa);
    }

    public function test_si_pasa_a_ser_empleada_deja_de_ser_externa(): void
    {
        $this->actingAs($this->admin)->post(route('personal.accesos.store'), $this->datos());
        $externa = User::where('email', 'externa@example.test')->sole();
        $p = app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Carla', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-9',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01',
        ]);

        // Se enlaza desde su ficha, como cualquier cuenta sin ficha.
        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), ['usa_sistema' => '1', 'user_id' => (string) $externa->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($externa->id, $p->fresh()->user_id);
        $this->assertFalse($externa->fresh()->cuenta_externa);
    }
}
