<?php

namespace Tests\Feature\Asistencia;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Asignar y liberar ranuras DESDE LA WEB.
 *
 * Lo que se prueba acá no son las reglas —esas ya las fija
 * {@see ReutilizacionRanuraTest} sobre los servicios— sino que la pantalla las
 * USA en vez de reimplementarlas: mismo rechazo, mismo mensaje, misma auditoría y
 * el mismo respeto al historial que desde la consola.
 *
 * La pantalla NO enrola nada: guardar la plantilla del dedo es un acto físico del
 * AS608. Acá solo se anota a quién corresponde la ranura.
 */
class AdministracionHuellasTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $lector;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create([
            'codigo' => 'lector-entrada', 'nombre' => 'Entrada principal',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function soloVer(): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo(PermisoSistema::AsistenciaVer->value);
    }

    private function empleado(string $nombres = 'Ana', string $apellidos = 'Pérez'): AsistenciaEmpleado
    {
        return AsistenciaEmpleado::factory()->create(['nombres' => $nombres, 'apellidos' => $apellidos]);
    }

    private function asignar(AsistenciaEmpleado $empleado, int $ranura, ?AsistenciaDispositivo $lector = null)
    {
        return $this->actingAs($this->admin())->post(
            route('asistencia.empleados.huellas.store', $empleado),
            [
                'asistencia_dispositivo_id' => ($lector ?? $this->lector)->id,
                'fingerprint_id' => $ranura,
            ],
        );
    }

    // ─────────────────────────────── Asignar ───────────────────────────────

    public function test_se_asigna_una_ranura_a_una_persona(): void
    {
        $empleado = $this->empleado();

        $this->asignar($empleado, 7)->assertSessionHas('status');

        $huella = AsistenciaHuella::sole();
        $this->assertSame($empleado->id, $huella->asistencia_empleado_id);
        $this->assertSame(7, $huella->fingerprint_id);
        $this->assertTrue($huella->activo);
    }

    /**
     * El mensaje de error tiene que decir QUIÉN ocupa la ranura: es la información
     * con la que se resuelve. Y llega tal cual lo produce el dominio, para que no
     * diga una cosa en la web y otra en la consola.
     */
    public function test_una_ranura_ocupada_se_rechaza_diciendo_quien_la_tiene(): void
    {
        $this->asignar($this->empleado('Ana', 'Pérez'), 7);

        $beto = $this->empleado('Beto', 'Ramos');
        $respuesta = $this->asignar($beto, 7);

        $respuesta->assertSessionHas('error');
        $this->assertStringContainsString('Ana Pérez', session('error'));
        $this->assertStringContainsString('lector-entrada', session('error'));

        // Y no se creó nada.
        $this->assertSame(1, AsistenciaHuella::count());
        $this->assertSame(0, $beto->huellas()->count());
    }

    public function test_una_ranura_liberada_se_puede_reasignar_desde_la_web(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $this->asignar($ana, 7);
        $deAna = AsistenciaHuella::sole();

        $this->actingAs($this->admin())
            ->patch(route('asistencia.huellas.liberar', $deAna))
            ->assertSessionHas('status');

        $beto = $this->empleado('Beto', 'Ramos');
        $this->asignar($beto, 7)->assertSessionHas('status');

        // Dos filas: la histórica de Ana y la vigente de Beto.
        $this->assertSame(2, AsistenciaHuella::count());
        $this->assertSame($ana->id, $deAna->fresh()->asistencia_empleado_id, 'La fila de Ana cambió de dueño.');
        $this->assertFalse($deAna->fresh()->activo);
        $this->assertSame($beto->id, AsistenciaHuella::query()->where('activo', true)->sole()->asistencia_empleado_id);
    }

    /** Un lector desactivado no puede recibir asignaciones: no podría marcar nada. */
    public function test_no_se_asigna_una_ranura_de_un_lector_desactivado(): void
    {
        $apagado = AsistenciaDispositivo::factory()->inactivo()->create(['nombre' => 'Bodega']);

        $this->asignar($this->empleado(), 7, $apagado)->assertSessionHas('error');

        $this->assertSame(0, AsistenciaHuella::count());
    }

    public function test_la_ranura_debe_ser_un_numero_valido(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($this->admin())
            ->post(route('asistencia.empleados.huellas.store', $empleado), [
                'asistencia_dispositivo_id' => $this->lector->id,
                'fingerprint_id' => 70000,
            ])
            ->assertSessionHasErrors('fingerprint_id');

        $this->assertSame(0, AsistenciaHuella::count());
    }

    // ─────────────────────────────── Liberar ───────────────────────────────

    public function test_liberar_no_borra_la_fila_y_deja_la_fecha(): void
    {
        $this->asignar($this->empleado(), 7);
        $huella = AsistenciaHuella::sole();

        $this->actingAs($this->admin())
            ->patch(route('asistencia.huellas.liberar', $huella))
            ->assertSessionHas('status');

        $huella->refresh();
        $this->assertFalse($huella->activo);
        $this->assertNotNull($huella->liberada_at);
        $this->assertDatabaseHas('asistencia_huellas', ['id' => $huella->id]);
    }

    public function test_liberar_dos_veces_avisa_y_no_cambia_nada(): void
    {
        $this->asignar($this->empleado(), 7);
        $huella = AsistenciaHuella::sole();

        $this->actingAs($this->admin())->patch(route('asistencia.huellas.liberar', $huella));
        $primeraFecha = $huella->fresh()->liberada_at;

        $this->actingAs($this->admin())
            ->patch(route('asistencia.huellas.liberar', $huella))
            ->assertSessionHas('error');

        $this->assertTrue($primeraFecha->equalTo($huella->fresh()->liberada_at));
    }

    /** No hay DELETE: liberar no borra, y la ruta no debe insinuar lo contrario. */
    public function test_no_existe_una_ruta_que_borre_una_asignacion(): void
    {
        $this->asignar($this->empleado(), 7);
        $huella = AsistenciaHuella::sole();

        $this->actingAs($this->admin())
            ->delete('/asistencia/huellas/'.$huella->id.'/liberar')
            ->assertStatus(405);

        $this->assertDatabaseHas('asistencia_huellas', ['id' => $huella->id]);
    }

    // ─────────────────────────────── Ficha ───────────────────────────────

    public function test_la_ficha_muestra_las_vigentes_y_las_historicas_por_separado(): void
    {
        $empleado = $this->empleado();
        $this->asignar($empleado, 7);
        $this->actingAs($this->admin())->patch(route('asistencia.huellas.liberar', AsistenciaHuella::sole()));
        $this->asignar($empleado, 9);

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $empleado))
            ->assertOk()
            ->assertSee('Ranuras vigentes')
            ->assertSee('Ranuras que tuvo antes')
            ->assertSee('No se borran ni se reasignan', escape: false);
    }

    public function test_la_ficha_avisa_cuando_la_persona_no_puede_marcar(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->empleado()))
            ->assertOk()
            ->assertSee('no puede marcar todavía', escape: false);
    }

    // ────────────────────────────── Auditoría ──────────────────────────────

    public function test_asignar_y_liberar_desde_la_web_quedan_auditados(): void
    {
        $this->asignar($this->empleado(), 7);
        $huella = AsistenciaHuella::sole();
        $this->actingAs($this->admin())->patch(route('asistencia.huellas.liberar', $huella));

        $actividad = Activity::query()
            ->where('log_name', 'asistencia')
            ->where('subject_type', AsistenciaHuella::class)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $actividad);
        $this->assertSame('asignó una huella a un empleado', $actividad->first()->description);
        $this->assertSame('modificó la asignación de una huella', $actividad->last()->description);
        // Y queda QUIÉN lo hizo, que es media auditoría.
        $this->assertNotNull($actividad->first()->causer_id);
    }

    // ───────────────────────────── Autorización ─────────────────────────────

    public function test_sin_permiso_de_gestion_no_se_asigna_ni_se_libera(): void
    {
        $empleado = $this->empleado();
        $this->asignar($empleado, 7);
        $huella = AsistenciaHuella::sole();

        $usuario = $this->soloVer();

        $this->actingAs($usuario)
            ->post(route('asistencia.empleados.huellas.store', $empleado), [
                'asistencia_dispositivo_id' => $this->lector->id, 'fingerprint_id' => 9,
            ])
            ->assertForbidden();

        $this->actingAs($usuario)
            ->patch(route('asistencia.huellas.liberar', $huella))
            ->assertForbidden();

        $this->assertSame(1, AsistenciaHuella::count());
        $this->assertTrue($huella->fresh()->activo);
    }

    /** Quien solo mira ve la ficha, pero sin el formulario de asignación. */
    public function test_quien_solo_ve_no_recibe_el_formulario_de_asignacion(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($this->soloVer())
            ->get(route('asistencia.empleados.show', $empleado))
            ->assertOk()
            ->assertDontSee('Asignar una ranura del sensor');
    }
}
