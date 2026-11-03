<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\PosiblesRepetidos;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una persona se carga una sola vez, en Personal. Asistencia y Rutas ya no la vuelven a
 * escribir: llevan a su ficha. Lo que quedó repetido de antes se lista, sin tocarlo.
 */
class UnaSolaAltaTest extends TestCase
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

        $this->admin = User::factory()->create(['activo' => true, 'name' => 'Administración']);
        $this->admin->syncPermissions(PermisoSistema::todos());
    }

    private function persona(array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, array_replace([
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'marca' => false, 'sale_rutas' => false,
        ], $cambios));
    }

    public function test_asistencia_nuevo_lleva_a_personal_y_no_crea_a_nadie(): void
    {
        $p = $this->persona();
        $this->persona(['nombres' => 'Bea', 'dui' => 'FICTICIO-2', 'marca' => true]);

        $this->actingAs($this->admin)->get(route('asistencia.empleados.create'))->assertOk()
            ->assertSee('Cada persona se carga una sola vez')->assertSee(route('planilla.empleados.create'), false)
            ->assertSee(route('personal.empleados.show', 'p'.$p->id), false)->assertDontSee('Bea Ficticia')
            ->assertDontSee('name="nombres"', false);

        $antes = AsistenciaEmpleado::count();
        $this->actingAs($this->admin)->post(route('asistencia.empleados.store'), [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'codigo' => 'F-1',
        ])->assertSessionHasErrors();
        $this->assertSame($antes, AsistenciaEmpleado::count());
    }

    public function test_rutas_nueva_persona_lleva_a_personal_y_no_crea_a_nadie(): void
    {
        $this->persona();

        $this->actingAs($this->admin)->get(route('rutas.personal.create'))
            ->assertRedirect(route('planilla.empleados.create'));

        $this->actingAs($this->admin)->post(route('rutas.personal.store'), ['nombre' => 'Ana Ficticia', 'funciones' => ['vendedor']])
            ->assertSessionHasErrors('nombre');
        $this->assertSame(0, PersonalRuta::count());
    }

    public function test_sin_permiso_de_personal_no_ofrece_fichas(): void
    {
        $this->persona();
        $soloRutas = User::factory()->create(['activo' => true]);
        $soloRutas->syncPermissions(['rutas.ver', PermisoSistema::RutasPersonalVer->value, PermisoSistema::RutasPersonalGestionar->value]);

        $this->actingAs($soloRutas)->get(route('rutas.personal.create'))->assertOk()
            ->assertSee('hace falta poder gestionar Personal')->assertDontSee('Ana Ficticia');
    }

    public function test_con_planilla_apagada_asistencia_y_rutas_siguen_como_antes(): void
    {
        config(['planilla.enabled' => false]);

        $this->actingAs($this->admin)->get(route('asistencia.empleados.create'))->assertOk()->assertSee('name="nombres"', false);
        $this->actingAs($this->admin)->get(route('rutas.personal.create'))->assertOk()->assertSee('name="nombre"', false);
        $this->actingAs($this->admin)->post(route('rutas.personal.store'), ['nombre' => 'Bea Ficticia'])->assertSessionHasNoErrors();
        $this->assertSame(1, PersonalRuta::count());
    }

    public function test_rutas_lleva_a_la_ficha_y_no_cambia_sus_enlaces(): void
    {
        $p = $this->persona(['sale_rutas' => true]);
        $ruta = $p->personalRuta;
        $otro = User::factory()->create(['activo' => true]);
        $ficha = route('personal.empleados.show', 'p'.$p->id);

        $this->actingAs($this->admin)->get(route('rutas.personal.index'))->assertRedirect(route('personal.empleados'));
        $this->actingAs($this->admin)->get(route('rutas.personal.show', $ruta))->assertRedirect($ficha);
        $this->actingAs($this->admin)->get(route('rutas.personal.edit', $ruta))->assertRedirect($ficha);

        $this->actingAs($this->admin)->put(route('rutas.personal.update', $ruta), [
            'user_id' => $otro->id, 'telefono' => '0000-0000', 'funciones' => ['vendedor'],
        ])->assertSessionHasNoErrors();

        $this->assertNull($ruta->fresh()->user_id);
        $this->assertSame('0000-0000', $ruta->fresh()->telefono);
    }

    public function test_hacerlo_vendedor_desde_usuarios_usa_su_ficha_de_personal(): void
    {
        $p = $this->persona();
        $cuenta = app(CrearUsuario::class)->crear([
            'name' => 'Anita', 'email' => 'ana@icloud.com', 'password' => 'Clave-Ficticia-2026', 'activo' => true, 'rol' => 'contabilidad',
        ]);
        app(AltaEmpleado::class)->completar($this->admin, $p, ['usa_sistema' => true, 'user_id' => $cuenta->id]);
        // Otra persona de Personal con el mismo nombre en Rutas: no se toma por el nombre.
        $ajena = $this->persona(['nombres' => 'Ana', 'apellidos' => 'Ficticia', 'dui' => 'FICTICIO-2', 'sale_rutas' => true, 'es_otra_persona' => true]);

        $this->actingAs($this->admin)->put(route('usuarios.update', $cuenta), [
            'name' => 'Ana Ficticia', 'email' => 'ana@icloud.com', 'rol' => 'vendedor', 'activo' => '1',
        ])->assertSessionHasNoErrors();

        $p = $p->fresh();
        $this->assertNotNull($p->personal_ruta_id);
        $this->assertSame($cuenta->id, $p->personalRuta->user_id);
        $this->assertNotSame($ajena->personal_ruta_id, $p->personal_ruta_id);
        $this->assertNull($ajena->personalRuta->fresh()->user_id);
    }

    public function test_posibles_repetidos_solo_lista(): void
    {
        $p = $this->persona(['marca' => true]);
        // De antes: la misma Ana cargada aparte en Rutas y en Usuarios.
        $suelta = PersonalRuta::create(['nombre' => 'Ana', 'activo' => true]);
        $cuenta = User::factory()->create(['name' => 'Ana María Ficticia', 'email' => 'ana@icloud.com']);
        // Otra persona, cargada en Rutas y Asistencia pero enlazada entre sí: no es repetida.
        $asistencia = AsistenciaEmpleado::create(['nombres' => 'Carla', 'apellidos' => 'Prueba', 'activo' => true]);
        PersonalRuta::create(['nombre' => 'Carla Prueba', 'activo' => true, 'asistencia_empleado_id' => $asistencia->id]);

        $grupos = app(PosiblesRepetidos::class)->grupos();

        $this->assertCount(1, $grupos);
        $this->assertEqualsCanonicalizing(
            [['planilla', $p->id], ['rutas', $suelta->id], ['usuario', $cuenta->id]],
            $grupos->first()->map(fn ($x) => [$x['tipo'], $x['id']])->all(),
        );

        $this->actingAs($this->admin)->get(route('personal.repetidos'))->assertOk()
            ->assertSee(route('personal.empleados.show', 'p'.$p->id), false)
            ->assertSee(route('rutas.personal.show', $suelta), false)
            ->assertSee(route('usuarios.show', $cuenta), false)
            ->assertDontSee('Carla Prueba');
        $this->actingAs($this->admin)->get(route('personal.empleados'))->assertOk()->assertSee(route('personal.repetidos'), false);

        // Nada cambió.
        $this->assertNull($suelta->fresh()->user_id);
        $this->assertNull(PlanillaEmpleado::find($p->id)->user_id);
    }

    public function test_posibles_repetidos_pide_gestionar_personal(): void
    {
        $soloVe = User::factory()->create(['activo' => true]);
        $soloVe->syncPermissions([PermisoSistema::PlanillaVer->value]);

        $this->actingAs($soloVe)->get(route('personal.repetidos'))->assertForbidden();
    }
}
