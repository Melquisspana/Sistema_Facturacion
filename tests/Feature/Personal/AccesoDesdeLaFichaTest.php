<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un solo lugar para el acceso de una persona: su ficha, en «Acceso y módulos». Ahí se le
 * cambia el rol o el correo, la contraseña, se la desactiva y se ve su historial. Lo hace quien
 * tiene `usuarios.gestionar`, igual que antes en Usuarios; quien no, no ve nada y recibe 403.
 */
class AccesoDesdeLaFichaTest extends TestCase
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

    /** Ana, con acceso de Contabilidad dado desde su ficha. */
    private function personaConAcceso(): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01',
            'usa_sistema' => true, 'rol' => 'contabilidad', 'email' => 'ana@example.test',
            'password' => 'Clave-Ficticia-2026', 'password_confirmation' => 'Clave-Ficticia-2026',
        ]);
    }

    public function test_la_ficha_ofrece_las_acciones_del_acceso(): void
    {
        $p = $this->personaConAcceso();

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()
            ->assertSeeInOrder(['Acceso y módulos', 'ana@example.test', 'Cambiar rol o correo', 'Cambiar contraseña', 'Desactivar acceso', 'Historial del acceso', 'asignó el rol contabilidad'])
            ->assertSee(route('personal.accesos.update', $p->user_id), false)
            ->assertSee(route('personal.accesos.password', $p->user_id), false)
            ->assertSee(route('personal.accesos.toggle-activo', $p->user_id), false)
            // Eliminar no se ofrece a quien tiene ficha: se desactiva o se le da de baja.
            ->assertDontSee('Eliminar cuenta');
    }

    public function test_cambia_rol_correo_contrasena_y_estado_desde_la_ficha(): void
    {
        $p = $this->personaConAcceso();
        $cuenta = $p->usuario;
        $ficha = route('personal.empleados.show', 'p'.$p->id);

        $this->actingAs($this->admin)->put(route('personal.accesos.update', $cuenta), ['email' => 'ana.nueva@example.test', 'rol' => 'facturacion'])
            ->assertRedirect($ficha)->assertSessionHas('status');
        $cuenta->refresh();
        $this->assertTrue($cuenta->hasRole('facturacion'));
        $this->assertSame('ana.nueva@example.test', $cuenta->email);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'usuario', 'subject_id' => $cuenta->id, 'description' => 'cambió el rol de contabilidad a facturacion']);

        $this->actingAs($this->admin)->put(route('personal.accesos.password', $cuenta), [
            'password' => 'Otra-Clave-Ficticia-2026', 'password_confirmation' => 'Otra-Clave-Ficticia-2026',
        ])->assertRedirect($ficha);
        $this->assertTrue(Hash::check('Otra-Clave-Ficticia-2026', $cuenta->fresh()->password));

        $this->actingAs($this->admin)->patch(route('personal.accesos.toggle-activo', $cuenta))->assertRedirect($ficha);
        $this->assertFalse($cuenta->fresh()->activo);
        $this->actingAs($this->admin)->get($ficha)->assertOk()->assertSee('Activar acceso');
        $this->actingAs($this->admin)->patch(route('personal.accesos.toggle-activo', $cuenta))->assertRedirect($ficha);
        $this->assertTrue($cuenta->fresh()->activo);
    }

    public function test_los_errores_vuelven_a_la_ficha_en_el_bloque_del_acceso(): void
    {
        $p = $this->personaConAcceso();
        $ficha = route('personal.empleados.show', 'p'.$p->id);

        $this->actingAs($this->admin)->from($ficha)->put(route('personal.accesos.update', $p->usuario), ['email' => 'no-es-correo', 'rol' => 'contabilidad'])
            ->assertRedirect($ficha)->assertSessionHasErrorsIn('acceso', 'email');
        $this->assertSame('ana@example.test', $p->usuario->fresh()->email);

        $this->actingAs($this->admin)->get($ficha)->assertOk()->assertSee('Revisá estos datos. No se guardó nada.');
    }

    public function test_sin_permiso_de_usuarios_no_ve_ni_puede_cambiar_el_acceso(): void
    {
        $p = $this->personaConAcceso();
        $cuenta = $p->usuario;
        $jefePersonal = User::factory()->create(['activo' => true]);
        $jefePersonal->syncPermissions([PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaGestionar->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($jefePersonal)->get(route('personal.empleados.show', 'p'.$p->id))->assertOk()
            ->assertSee('ana@example.test')
            ->assertDontSee('Cambiar rol o correo')->assertDontSee('Desactivar acceso')->assertDontSee('Historial del acceso');

        $this->actingAs($jefePersonal)->put(route('personal.accesos.update', $cuenta), ['email' => 'otra@example.test', 'rol' => 'administrador'])->assertForbidden();
        $this->actingAs($jefePersonal)->put(route('personal.accesos.password', $cuenta), [
            'password' => 'Otra-Clave-Ficticia-2026', 'password_confirmation' => 'Otra-Clave-Ficticia-2026',
        ])->assertForbidden();
        $this->actingAs($jefePersonal)->patch(route('personal.accesos.toggle-activo', $cuenta))->assertForbidden();
        $this->actingAs($jefePersonal)->get(route('personal.accesos.index'))->assertForbidden();
        $this->actingAs($jefePersonal)->get(route('personal.accesos.create'))->assertForbidden();

        $cuenta->refresh();
        $this->assertTrue($cuenta->hasRole('contabilidad'));
        $this->assertTrue($cuenta->activo);
        $this->assertSame('ana@example.test', $cuenta->email);
        $this->assertTrue(Hash::check('Clave-Ficticia-2026', $cuenta->password));
    }

    /** Quien gestiona usuarios pero no ve Personal sigue pudiendo, desde la página de la cuenta. */
    public function test_sin_ver_personal_lo_hace_desde_la_pagina_de_la_cuenta(): void
    {
        $p = $this->personaConAcceso();
        $soloUsuarios = User::factory()->create(['activo' => true]);
        $soloUsuarios->syncPermissions([PermisoSistema::UsuariosGestionar->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($soloUsuarios)->get(route('personal.accesos.show', $p->user_id))->assertOk()
            ->assertSee('Es la cuenta de Ana Ficticia')->assertSee('Cambiar rol o correo')
            ->assertDontSee('Eliminar cuenta');
        $this->actingAs($soloUsuarios)->patch(route('personal.accesos.toggle-activo', $p->user_id))
            ->assertRedirect(route('personal.accesos.show', $p->user_id));
        $this->assertFalse($p->usuario->fresh()->activo);
    }

    public function test_el_menu_de_personal_lleva_a_accesos_solo_a_quien_gestiona_usuarios(): void
    {
        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee('Accesos al sistema')->assertSee('aria-current="page"', false);

        $soloVe = User::factory()->create(['activo' => true]);
        $soloVe->syncPermissions([PermisoSistema::PlanillaVer->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($soloVe)->get(route('personal.empleados'))->assertOk()
            ->assertDontSee(route('personal.accesos.index'), false);
    }
}
