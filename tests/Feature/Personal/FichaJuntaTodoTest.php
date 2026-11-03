<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La ficha de Personal muestra todo lo de la persona y lleva a cada parte, y Usuarios usa
 * los mismos campos, en el mismo orden, que «Agregar acceso» de la ficha.
 */
class FichaJuntaTodoTest extends TestCase
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
        $this->admin->syncPermissions(array_merge(PermisoSistema::todos(), ['rutas.ver']));
    }

    public function test_la_ficha_muestra_usuario_asistencia_y_rutas_con_su_camino(): void
    {
        $p = app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'marca' => true,
            'usa_sistema' => true, 'rol' => 'vendedor', 'email' => 'ana@icloud.com',
            'password' => 'Clave-Ficticia-2026', 'password_confirmation' => 'Clave-Ficticia-2026',
        ]);

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()
            ->assertSeeInOrder(['Acceso y módulos', 'Usuario', 'ana@icloud.com', 'Asistencia', 'Marca con huella', 'Rutas', 'Vendedor'])
            ->assertSee(route('usuarios.show', $p->user_id), false)
            ->assertSee(route('rutas.personal.show', $p->personal_ruta_id), false)
            ->assertSee('href="#asistencia"', false)->assertSee('id="asistencia"', false);

        // Y cada parte vuelve a la ficha.
        $ficha = route('personal.empleados.show', 'p'.$p->id);
        $this->actingAs($this->admin)->get(route('usuarios.show', $p->user_id))->assertOk()->assertSee($ficha, false);
        $this->actingAs($this->admin)->get(route('rutas.personal.show', $p->personal_ruta_id))->assertOk()->assertSee($ficha, false);
    }

    public function test_sin_modulos_la_ficha_lo_dice(): void
    {
        $p = app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Bea', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-2',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()
            ->assertSee('Sin usuario')->assertSee('No marca')->assertSee('No sale a rutas');
    }

    public function test_usuarios_y_la_ficha_piden_lo_mismo_en_el_mismo_orden(): void
    {
        $p = app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Bea', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-2',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01',
        ]);
        $orden = ['El rol dice qué puede hacer', 'Rol', 'Correo', 'Contraseña', 'Repetí la contraseña', 'Mínimo'];

        $this->actingAs($this->admin)->get(route('usuarios.create'))->assertOk()->assertSeeInOrder($orden);
        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()->assertSeeInOrder($orden);
    }
}
