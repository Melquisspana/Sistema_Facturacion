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

    public function test_quien_no_marca_se_corrige_desde_planilla_y_llega_a_rutas(): void
    {
        $p = $this->persona();

        $this->actingAs($this->admin)->post(route('planilla.empleados.nombre', $p), ['nombre' => '  Ana   Lucía Ficticia '])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Ana Lucía Ficticia', $p->fresh()->nombre);
        $this->assertSame('Ana Lucía Ficticia', $p->fresh()->personalRuta->nombre);
    }

    public function test_quien_marca_no_se_corrige_desde_planilla(): void
    {
        $p = $this->persona(['marca' => true]);

        $this->actingAs($this->admin)->post(route('planilla.empleados.nombre', $p), ['nombre' => 'Otro Nombre'])
            ->assertSessionHasErrors('nombre');

        $this->assertSame('Ana Ficticia', $p->fresh()->nombre);
    }

    public function test_sin_permiso_de_gestionar_no_se_corrige(): void
    {
        $p = $this->persona();
        $soloVe = $this->usuario([PermisoSistema::PlanillaVer->value]);

        $this->actingAs($soloVe)->post(route('planilla.empleados.nombre', $p), ['nombre' => 'Otro Nombre'])->assertForbidden();

        $this->assertSame('Ana Ficticia', $p->fresh()->nombre);
    }

    public function test_la_ficha_ofrece_corregir_el_nombre_solo_a_quien_no_marca(): void
    {
        $sinMarca = $this->persona();
        $conMarca = $this->persona(['marca' => true, 'nombres' => 'Bea', 'dui' => 'FICTICIO-2']);

        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$sinMarca->id))
            ->assertOk()->assertSee('Corregir nombre');
        $this->actingAs($this->admin)->get(route('personal.empleados.show', 'p'.$conMarca->id))
            ->assertOk()->assertDontSee('Corregir nombre');
    }

    public function test_abrir_una_quincena_viene_con_pago_quincenal(): void
    {
        $this->actingAs($this->admin)->get(route('planilla.create'))
            ->assertOk()->assertSee('Pago quincenal (dos por mes)');
    }
}
