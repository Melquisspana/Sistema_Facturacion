<?php

namespace Tests\Feature\Asistencia;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Administración de las personas que marcan.
 *
 * Tres garantías que no se pueden perder:
 *
 *  1. NO SE BORRA A NADIE. Ni existe el endpoint. Borrar a una persona borra su
 *     historial laboral, y la forma más sólida de impedirlo es que no haya por
 *     dónde pedirlo.
 *  2. `asistencia_empleados` NO ES `users`. El formulario no puede atar a alguien
 *     con una cuenta del sistema —y con sus permisos fiscales—.
 *  3. CADA ACCIÓN CON SU PERMISO. Ver es `asistencia.ver`; escribir es
 *     `asistencia.gestionar`, y no basta con lo primero.
 */
class AdministracionEmpleadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Solo puede MIRAR el módulo: ni crear, ni editar, ni activar. */
    private function soloVer(): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo(PermisoSistema::AsistenciaVer->value);
    }

    // ─────────────────────────────── Listado ───────────────────────────────

    public function test_el_listado_muestra_a_las_personas(): void
    {
        AsistenciaEmpleado::factory()->create(['nombres' => 'Ana María', 'apellidos' => 'Pérez Rivas']);

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.index'))
            ->assertOk()
            ->assertSee('Ana María Pérez Rivas');
    }

    /**
     * La columna de ranuras cuenta las VIGENTES. Alguien con una asignación
     * histórica y ninguna activa no puede marcar, y la pantalla tiene que decir
     * «Sin ranura», no «1».
     */
    public function test_el_listado_solo_cuenta_las_ranuras_vigentes(): void
    {
        $empleado = AsistenciaEmpleado::factory()->create();
        $lector = AsistenciaDispositivo::factory()->create();

        AsistenciaHuella::factory()->liberada()->create([
            'asistencia_empleado_id' => $empleado->id,
            'asistencia_dispositivo_id' => $lector->id,
            'fingerprint_id' => 5,
        ]);

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.index'))
            ->assertOk()
            ->assertSee('Sin ranura');
    }

    public function test_el_listado_filtra_por_texto_y_por_estado(): void
    {
        AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);
        AsistenciaEmpleado::factory()->inactivo()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.index', ['q' => 'Beto']))
            ->assertOk()
            ->assertSee('Beto Ramos')
            ->assertDontSee('Ana Pérez');

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.index', ['activo' => '1']))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertDontSee('Beto Ramos');
    }

    public function test_el_listado_vacio_lo_explica(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.index'))
            ->assertOk()
            ->assertSee('Todavía no hay personas dadas de alta.');
    }

    // ─────────────────────────────── Alta ───────────────────────────────

    public function test_se_da_de_alta_a_una_persona(): void
    {
        $this->actingAs($this->admin())
            ->post(route('asistencia.empleados.store'), [
                'nombres' => 'Ana María',
                'apellidos' => 'Pérez Rivas',
                'codigo' => 'RH-014',
                'fecha_ingreso' => '2026-01-15',
            ])
            ->assertSessionHasNoErrors();

        $empleado = AsistenciaEmpleado::sole();
        $this->assertSame('Ana María Pérez Rivas', $empleado->nombreCompleto());
        $this->assertSame('RH-014', $empleado->codigo);
        $this->assertTrue($empleado->activo, 'Quien se da de alta nace activo.');
    }

    public function test_el_codigo_de_planilla_no_se_puede_repetir(): void
    {
        AsistenciaEmpleado::factory()->create(['codigo' => 'RH-014']);

        $this->actingAs($this->admin())
            ->post(route('asistencia.empleados.store'), [
                'nombres' => 'Beto', 'apellidos' => 'Ramos', 'codigo' => 'RH-014',
            ])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, AsistenciaEmpleado::count());
    }

    public function test_nombres_y_apellidos_son_obligatorios(): void
    {
        $this->actingAs($this->admin())
            ->post(route('asistencia.empleados.store'), ['nombres' => '', 'apellidos' => ''])
            ->assertSessionHasErrors(['nombres', 'apellidos']);

        $this->assertSame(0, AsistenciaEmpleado::count());
    }

    /**
     * El puente con `users` existe en el esquema y es opcional, pero atarlo desde
     * este formulario dejaría que quien administra personal asocie a cualquiera
     * con cualquier cuenta —y con sus permisos fiscales—.
     */
    public function test_el_formulario_no_puede_atar_a_una_persona_con_un_usuario_del_sistema(): void
    {
        $otro = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('asistencia.empleados.store'), [
                'nombres' => 'Ana', 'apellidos' => 'Pérez', 'user_id' => $otro->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(AsistenciaEmpleado::sole()->user_id);
    }

    // ─────────────────────────────── Edición ───────────────────────────────

    public function test_se_corrigen_los_datos_sin_tocar_marcaciones_ni_ranuras(): void
    {
        $empleado = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Peres']);
        $lector = AsistenciaDispositivo::factory()->create();
        $huella = AsistenciaHuella::factory()->create([
            'asistencia_empleado_id' => $empleado->id,
            'asistencia_dispositivo_id' => $lector->id,
            'fingerprint_id' => 3,
        ]);

        $this->actingAs($this->admin())
            ->put(route('asistencia.empleados.update', $empleado), [
                'nombres' => 'Ana María', 'apellidos' => 'Pérez',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ana María Pérez', $empleado->fresh()->nombreCompleto());
        $this->assertTrue($huella->fresh()->activo, 'Corregir un nombre no toca sus ranuras.');
    }

    public function test_al_editar_el_codigo_no_choca_consigo_mismo(): void
    {
        $empleado = AsistenciaEmpleado::factory()->create(['codigo' => 'RH-014']);

        $this->actingAs($this->admin())
            ->put(route('asistencia.empleados.update', $empleado), [
                'nombres' => 'Ana', 'apellidos' => 'Pérez', 'codigo' => 'RH-014',
            ])
            ->assertSessionHasNoErrors();
    }

    // ───────────────────────── Activar / desactivar ─────────────────────────

    public function test_desactivar_conserva_el_historial_y_las_ranuras(): void
    {
        $empleado = AsistenciaEmpleado::factory()->create();
        $lector = AsistenciaDispositivo::factory()->create();
        $huella = AsistenciaHuella::factory()->create([
            'asistencia_empleado_id' => $empleado->id,
            'asistencia_dispositivo_id' => $lector->id,
            'fingerprint_id' => 3,
        ]);

        $this->actingAs($this->admin())
            ->patch(route('asistencia.empleados.toggle-activo', $empleado))
            ->assertSessionHas('status');

        $this->assertFalse($empleado->fresh()->activo);
        // Desactivar NO libera la ranura: la plantilla sigue en el sensor y la
        // asignación sigue siendo suya. Liberarla es un acto aparte.
        $this->assertTrue($huella->fresh()->activo);
        $this->assertDatabaseHas('asistencia_empleados', ['id' => $empleado->id]);
    }

    public function test_no_existe_ninguna_forma_de_borrar_a_una_persona(): void
    {
        $empleado = AsistenciaEmpleado::factory()->create();

        $this->actingAs($this->admin())
            ->delete('/asistencia/empleados/'.$empleado->id)
            ->assertStatus(405);

        $this->assertDatabaseHas('asistencia_empleados', ['id' => $empleado->id]);
    }

    // ──────────────────────────── Auditoría ────────────────────────────

    public function test_alta_edicion_y_desactivacion_quedan_auditadas(): void
    {
        $this->actingAs($this->admin())
            ->post(route('asistencia.empleados.store'), ['nombres' => 'Ana', 'apellidos' => 'Pérez']);

        $empleado = AsistenciaEmpleado::sole();

        $this->actingAs($this->admin())
            ->put(route('asistencia.empleados.update', $empleado), ['nombres' => 'Ana María', 'apellidos' => 'Pérez']);
        $this->actingAs($this->admin())
            ->patch(route('asistencia.empleados.toggle-activo', $empleado));

        $descripciones = Activity::query()
            ->where('log_name', 'asistencia')
            ->where('subject_type', AsistenciaEmpleado::class)
            ->orderBy('id')
            ->pluck('description')
            ->all();

        $this->assertSame([
            'dio de alta al empleado',
            'actualizó al empleado',
            'actualizó al empleado',
        ], $descripciones);
    }

    // ──────────────────────────── Autorización ────────────────────────────

    public function test_quien_solo_ve_puede_consultar_pero_no_escribir(): void
    {
        $empleado = AsistenciaEmpleado::factory()->create();
        $usuario = $this->soloVer();

        $this->actingAs($usuario)->get(route('asistencia.empleados.index'))->assertOk();
        $this->actingAs($usuario)->get(route('asistencia.empleados.show', $empleado))->assertOk();
    }

    /** @param  array{0: string, 1: string, 2: array<string, mixed>}  $accion */
    #[DataProvider('accionesDeEscritura')]
    public function test_sin_permiso_de_gestion_no_se_puede_escribir(string $verbo, string $ruta, array $datos): void
    {
        $empleado = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);
        $url = str_contains($ruta, '{empleado}')
            ? route(str_replace('{empleado}', '', $ruta), $empleado)
            : route($ruta);

        $this->actingAs($this->soloVer())->$verbo($url, $datos)->assertForbidden();

        $this->assertSame('Ana Pérez', $empleado->fresh()->nombreCompleto());
        $this->assertTrue($empleado->fresh()->activo);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function accionesDeEscritura(): array
    {
        return [
            'formulario de alta' => ['get', 'asistencia.empleados.create', []],
            'crear' => ['post', 'asistencia.empleados.store', ['nombres' => 'X', 'apellidos' => 'Y']],
            'formulario de edición' => ['get', 'asistencia.empleados.edit{empleado}', []],
            'editar' => ['put', 'asistencia.empleados.update{empleado}', ['nombres' => 'X', 'apellidos' => 'Y']],
            'activar o desactivar' => ['patch', 'asistencia.empleados.toggle-activo{empleado}', []],
        ];
    }
}
