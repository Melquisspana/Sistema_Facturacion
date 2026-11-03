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
 * Un solo nombre por persona: corregirlo en un lado lo deja igual en Planilla, Asistencia
 * y Rutas. Antes, corregirlo en planilla dejaba el nombre viejo en los otros dos módulos.
 */
class NombreUnicoTest extends TestCase
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

        $this->admin = $this->usuario(PermisoSistema::todos());
    }

    /** @param  array<int, string>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function persona(array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, array_replace([
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '408.80',
            'marca' => false, 'sale_rutas' => true,
        ], $cambios));
    }

    public function test_corregir_en_asistencia_llega_a_planilla_y_rutas(): void
    {
        $p = $this->persona(['marca' => true]);
        $asistencia = $p->asistencia;

        $this->actingAs($this->admin)->put(route('asistencia.empleados.update', $asistencia), [
            'nombres' => 'Ana Lucía', 'apellidos' => 'Ficticia Prueba',
        ])->assertRedirect();

        $this->assertSame('Ana Lucía Ficticia Prueba', $p->fresh()->nombre);
        $this->assertSame('Ana Lucía Ficticia Prueba', $p->fresh()->personalRuta->nombre);
    }

    /** @return array<string, string> */
    private function datos(array $cambios = []): array
    {
        return array_replace(['documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1', 'cargo' => 'Ventas', 'alta_el' => '2026-01-01'], $cambios);
    }

    public function test_quien_no_marca_se_corrige_en_la_ficha_y_llega_a_rutas(): void
    {
        $p = $this->persona();

        $this->actingAs($this->admin)->post(route('planilla.empleados.datos', $p), $this->datos(['nombre' => '  Ana   Lucía Ficticia ']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Ana Lucía Ficticia', $p->fresh()->nombre);
        $this->assertSame('Ana Lucía Ficticia', $p->fresh()->personalRuta->nombre);
        $this->assertSame('Ventas', $p->fresh()->cargo);
    }

    public function test_quien_marca_se_corrige_en_la_ficha_con_nombres_y_apellidos(): void
    {
        $p = $this->persona(['marca' => true]);

        $this->actingAs($this->admin)->post(route('planilla.empleados.datos', $p), $this->datos([
            'nombres' => 'Ana María', 'apellidos' => 'Ficticia Prueba', 'alta_el' => '2025-10-01',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $p = $p->fresh();
        $this->assertSame('Ana María Ficticia Prueba', $p->nombre);
        $this->assertSame('Ana María', $p->asistencia->nombres);
        $this->assertSame('2025-10-01', $p->asistencia->fecha_ingreso->toDateString());
        $this->assertSame('Ana María Ficticia Prueba', $p->personalRuta->nombre);
    }

    public function test_el_documento_no_puede_repetir_el_de_otra_persona(): void
    {
        $p = $this->persona();
        $this->persona(['nombres' => 'Bea', 'dui' => 'FICTICIO-2']);

        $this->actingAs($this->admin)->post(route('planilla.empleados.datos', $p), $this->datos(['nombre' => 'Ana Ficticia', 'dui' => 'FICTICIO-2']))
            ->assertSessionHasErrors('dui');
        // Guardar sin cambiar el propio documento no choca consigo mismo.
        $this->actingAs($this->admin)->post(route('planilla.empleados.datos', $p), $this->datos(['nombre' => 'Ana Ficticia']))
            ->assertSessionHasNoErrors();
    }

    public function test_sin_permiso_de_gestionar_no_se_corrige(): void
    {
        $p = $this->persona();
        $soloVe = $this->usuario([PermisoSistema::PlanillaVer->value]);

        $this->actingAs($soloVe)->post(route('planilla.empleados.datos', $p), $this->datos(['nombre' => 'Otro Nombre']))->assertForbidden();

        $this->assertSame('Ana Ficticia', $p->fresh()->nombre);
    }

    public function test_despues_del_alta_se_le_puede_dar_usuario_de_vendedor(): void
    {
        $p = $this->persona(['sale_rutas' => false]);

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), [
            'usa_sistema' => '1', 'rol' => 'vendedor', 'email' => 'ana.ficticia@example.test',
            'password' => 'Clave-Ficticia-2026', 'password_confirmation' => 'Clave-Ficticia-2026',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $p = $p->fresh();
        $this->assertNotNull($p->usuario);
        $this->assertTrue($p->usuario->hasRole('vendedor'));
        // Vendedor sale a rutas: se le crea su ficha, enlazada al usuario.
        $this->assertNotNull($p->personalRuta);
        $this->assertSame($p->user_id, $p->personalRuta->user_id);
    }

    public function test_despues_del_alta_se_le_puede_poner_a_marcar(): void
    {
        $p = $this->persona();

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), [
            'marca' => '1', 'nombres' => 'Ana', 'apellidos' => 'Ficticia Prueba',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $p = $p->fresh();
        $this->assertNotNull($p->asistencia);
        $this->assertSame('Ana Ficticia Prueba', $p->nombre);
        $this->assertSame($p->asistencia_empleado_id, $p->personalRuta->asistencia_empleado_id);
    }

    public function test_agregar_sin_elegir_nada_avisa(): void
    {
        $p = $this->persona();

        $this->actingAs($this->admin)->post(route('planilla.empleados.modulos', $p), ['marca' => '0'])
            ->assertSessionHasErrors('modulos');
    }

    public function test_la_ficha_tiene_un_solo_editar_y_ofrece_lo_que_falta(): void
    {
        $sinMarca = $this->persona();
        $conMarca = $this->persona(['marca' => true, 'nombres' => 'Bea', 'dui' => 'FICTICIO-2']);

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$sinMarca->id))
            ->assertOk()->assertSee('Editar datos')->assertSee('Nombre completo')
            ->assertSee('Marca con huella')->assertSee('Darle acceso al sistema')->assertDontSee('Sale a rutas');
        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$conMarca->id))
            ->assertOk()->assertSee('Editar datos')->assertDontSee('Nombre completo')
            ->assertDontSee(route('asistencia.empleados.edit', $conMarca->asistencia_empleado_id), false);
    }

    public function test_abrir_una_quincena_viene_con_pago_quincenal(): void
    {
        $this->actingAs($this->admin)->get(route('planilla.create'))
            ->assertOk()->assertSee('Pago quincenal (dos por mes)');
    }
}
