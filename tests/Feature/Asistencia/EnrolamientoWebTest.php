<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Models\User;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\CrearOrdenEnrolamiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El lado HUMANO del enrolamiento: la tarjeta de la ficha del empleado.
 *
 * ─────────── La garantía que ordena todas estas pruebas ───────────
 *
 * **Ningún navegador puede hacerse pasar por un lector.** Desde la web solo se
 * puede PEDIR un registro y CANCELARLO. Completar una orden, fingir un resultado o
 * fabricar una asignación exige el token del lector, que la web no conoce, no
 * muestra y no puede recuperar.
 *
 * Por eso acá se comprueba sobre todo lo que la web NO puede hacer.
 */
class EnrolamientoWebTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $lector;

    private AsistenciaEmpleado $ana;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada', 'nombre' => 'Entrada principal']);
        $this->lector->sincronizarIndice(162, []);
        $this->lector->refresh();

        $this->ana = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Puede VER asistencia pero no gestionarla. */
    private function mirona(): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo([PermisoSistema::AsistenciaVer->value]);
    }

    private function iniciar(User $quien, array $datos = []): TestResponse
    {
        return $this->actingAs($quien)->post(
            route('asistencia.empleados.enrolamiento.store', $this->ana),
            $datos + ['asistencia_dispositivo_id' => $this->lector->id],
        );
    }

    // ─────────────────────────── Iniciar ───────────────────────────

    public function test_iniciar_un_registro_crea_la_orden_sin_crear_la_huella(): void
    {
        $this->iniciar($this->admin())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $orden = AsistenciaOrdenEnrolamiento::sole();
        $this->assertSame($this->ana->id, $orden->asistencia_empleado_id);
        $this->assertSame($this->lector->id, $orden->asistencia_dispositivo_id);
        $this->assertSame(EstadoOrdenEnrolamiento::Pendiente, $orden->estado);
        $this->assertSame(0, $orden->ranura_reservada);
        $this->assertFalse($orden->ranura_manual);
        $this->assertNull($orden->asistencia_huella_id);

        // LA REGLA: pedirlo no graba nada.
        $this->assertSame(0, AsistenciaHuella::count());
    }

    /** El aviso dice qué hacer físicamente, que es lo que la persona necesita. */
    public function test_el_aviso_dice_a_quien_y_en_que_lector(): void
    {
        $this->iniciar($this->admin());

        $this->assertStringContainsString('Ana Pérez', session('status'));
        $this->assertStringContainsString('Entrada principal', session('status'));
    }

    public function test_queda_registrado_quien_lo_pidio(): void
    {
        $admin = $this->admin();
        $this->iniciar($admin);

        $this->assertSame($admin->id, AsistenciaOrdenEnrolamiento::sole()->solicitada_por_user_id);
    }

    public function test_la_orden_vive_tres_minutos(): void
    {
        $this->freezeTime();
        $this->iniciar($this->admin());

        // Contra `created_at` y no contra «ahora»: las dos columnas se guardan
        // truncadas al segundo, así que comparadas entre sí dan 180 exactos, sin
        // depender de en qué microsegundo cayó la petición.
        $orden = AsistenciaOrdenEnrolamiento::sole();
        $this->assertSame(3 * 60, (int) $orden->created_at->diffInSeconds($orden->expira_at));
    }

    // ──────────────────────── Autorización ────────────────────────

    public function test_ver_asistencia_no_alcanza_para_pedir_un_registro(): void
    {
        $this->iniciar($this->mirona())->assertForbidden();

        $this->assertSame(0, AsistenciaOrdenEnrolamiento::count());
    }

    public function test_sin_sesion_no_se_puede_pedir_un_registro(): void
    {
        $this->post(route('asistencia.empleados.enrolamiento.store', $this->ana), [
            'asistencia_dispositivo_id' => $this->lector->id,
        ])->assertRedirect(route('login'));

        $this->assertSame(0, AsistenciaOrdenEnrolamiento::count());
    }

    public function test_con_el_modulo_apagado_la_ruta_no_existe(): void
    {
        config()->set('asistencia.enabled', false);

        $this->iniciar($this->admin())->assertNotFound();
    }

    // ─────────────────── Lo que la web NO puede hacer ───────────────────

    /**
     * No hay ninguna ruta web que complete una orden. Es la garantía de que una
     * huella no puede aparecer sin que el sensor la haya grabado de verdad.
     */
    public function test_no_existe_ninguna_ruta_web_que_complete_una_orden(): void
    {
        $rutasWeb = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'enrolamiento') && ! str_starts_with($r->uri(), 'api/'))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
            ->values()->sort()->values()->all();

        $this->assertSame([
            'DELETE asistencia/empleados/{empleado}/enrolamiento/{orden}',
            'POST asistencia/empleados/{empleado}/enrolamiento',
        ], $rutasWeb, 'Solo pedir y cancelar. Nada más puede vivir en la web.');
    }

    /** Y un administrador con sesión tampoco puede usar las rutas del lector. */
    public function test_un_administrador_con_sesion_no_puede_resolver_una_orden(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);

        $this->actingAs($this->admin())
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => 'lo-que-sea', 'exito' => true, 'fingerprint_id' => 0,
            ])
            ->assertUnauthorized();

        $this->assertSame(0, AsistenciaHuella::count());
    }

    // ─────────────────────────── Cancelar ───────────────────────────

    public function test_cancelar_cierra_la_orden_sin_crear_nada(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);

        $this->actingAs($this->admin())
            ->delete(route('asistencia.empleados.enrolamiento.destroy', [$this->ana, $orden]))
            ->assertSessionHas('status');

        $orden->refresh();
        $this->assertSame(EstadoOrdenEnrolamiento::Cancelada, $orden->estado);
        $this->assertSame(MotivoFalloEnrolamiento::CanceladaPorOperador, $orden->motivo_fallo);
        $this->assertSame(0, AsistenciaHuella::count());
    }

    /** Y libera el buzón: se puede volver a intentar enseguida. */
    public function test_tras_cancelar_se_puede_pedir_otro_registro(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);
        $this->actingAs($this->admin())->delete(route('asistencia.empleados.enrolamiento.destroy', [$this->ana, $orden]));

        $this->iniciar($this->admin())->assertSessionHasNoErrors();

        $this->assertSame(1, AsistenciaOrdenEnrolamiento::query()->vivas()->count());
    }

    /** Cancelar dos veces no rompe nada ni miente. */
    public function test_cancelar_una_orden_ya_terminada_avisa_y_no_cambia_nada(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);
        $ruta = route('asistencia.empleados.enrolamiento.destroy', [$this->ana, $orden]);

        $this->actingAs($this->admin())->delete($ruta);
        $this->actingAs($this->admin())->delete($ruta)->assertSessionHas('error');

        $this->assertSame(EstadoOrdenEnrolamiento::Cancelada, $orden->refresh()->estado);
    }

    /** No se puede cancelar el registro de otra persona con una URL a mano. */
    public function test_no_se_puede_cancelar_la_orden_de_otra_persona(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);
        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);

        $this->actingAs($this->admin())
            ->delete(route('asistencia.empleados.enrolamiento.destroy', [$beto, $orden]))
            ->assertNotFound();

        $this->assertSame(EstadoOrdenEnrolamiento::Pendiente, $orden->refresh()->estado);
    }

    // ─────────────────── Los rechazos, explicados ───────────────────

    public function test_pedirlo_en_un_lector_sin_sincronizar_lo_explica(): void
    {
        $virgen = AsistenciaDispositivo::factory()->create(['codigo' => 'nuevo', 'nombre' => 'Recién instalado']);

        $this->iniciar($this->admin(), ['asistencia_dispositivo_id' => $virgen->id])
            ->assertSessionHas('error');

        $this->assertStringContainsString('todavía no ha sincronizado sus ranuras', session('error'));
        $this->assertSame(0, AsistenciaOrdenEnrolamiento::count());
    }

    public function test_pedirlo_dos_veces_en_el_mismo_lector_lo_explica(): void
    {
        $this->iniciar($this->admin());
        $this->iniciar($this->admin())->assertSessionHas('error');

        $this->assertStringContainsString('Ana Pérez', session('error'));
        $this->assertSame(1, AsistenciaOrdenEnrolamiento::count());
    }

    public function test_un_lector_inexistente_no_pasa_la_validacion(): void
    {
        $this->iniciar($this->admin(), ['asistencia_dispositivo_id' => 9999])
            ->assertSessionHasErrors('asistencia_dispositivo_id');
    }

    // ────────────────── El escape manual de «avanzadas» ──────────────────

    public function test_la_ranura_manual_se_respeta_y_queda_marcada(): void
    {
        $this->iniciar($this->admin(), ['ranura' => 40])->assertSessionHasNoErrors();

        $orden = AsistenciaOrdenEnrolamiento::sole();
        $this->assertSame(40, $orden->ranura_reservada);
        $this->assertTrue($orden->ranura_manual, 'Que la eligiera una persona tiene que quedar anotado.');
    }

    /** El escape NO se salta las protecciones: esa es la condición de la decisión 3. */
    public function test_la_ranura_manual_ya_asignada_se_rechaza(): void
    {
        app(AsignarHuella::class)($this->ana, $this->lector, 7);

        $this->iniciar($this->admin(), ['ranura' => 7])->assertSessionHas('error');

        $this->assertSame(0, AsistenciaOrdenEnrolamiento::count());
    }

    public function test_la_ranura_manual_ocupada_en_el_sensor_se_rechaza(): void
    {
        $this->lector->sincronizarIndice(162, [7]);

        $this->iniciar($this->admin(), ['ranura' => 7])->assertSessionHas('error');

        $this->assertSame(0, AsistenciaOrdenEnrolamiento::count());
    }

    public function test_la_ranura_manual_fuera_de_la_capacidad_se_rechaza(): void
    {
        $this->lector->sincronizarIndice(100, []);

        $this->iniciar($this->admin(), ['ranura' => 500])->assertSessionHas('error');

        $this->assertSame(0, AsistenciaOrdenEnrolamiento::count());
    }

    /** Sin escribir nada, sigue siendo automática. */
    public function test_dejar_la_ranura_vacia_es_automatica(): void
    {
        $this->iniciar($this->admin(), ['ranura' => ''])->assertSessionHasNoErrors();

        $orden = AsistenciaOrdenEnrolamiento::sole();
        $this->assertSame(0, $orden->ranura_reservada);
        $this->assertFalse($orden->ranura_manual);
    }

    // ─────────────────────────── La ficha ───────────────────────────

    public function test_la_ficha_ofrece_el_registro_y_muestra_la_orden_viva(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->ana))
            ->assertOk()
            ->assertSee('Registrar huella con el lector')
            ->assertViewHas('ordenViva', fn ($v) => $v?->id === $orden->id);
    }

    public function test_la_ficha_no_muestra_ninguna_orden_cuando_no_hay(): void
    {
        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->ana))
            ->assertOk()
            ->assertViewHas('ordenViva', null);
    }

    /** Una orden vencida no cuenta como viva, aunque su estado no se haya movido. */
    public function test_una_orden_vencida_no_se_muestra_como_viva(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);
        $orden->forceFill(['expira_at' => Carbon::now()->subMinute()])->save();

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->ana))
            ->assertOk()
            ->assertViewHas('ordenViva', null);
    }

    /** Los intentos anteriores se ven: es cómo se entiende por qué falló. */
    public function test_la_ficha_muestra_los_intentos_recientes(): void
    {
        AsistenciaOrdenEnrolamiento::factory()
            ->for($this->lector, 'dispositivo')->for($this->ana, 'empleado')
            ->fallida(MotivoFalloEnrolamiento::DedosNoCoinciden)
            ->create();

        $this->actingAs($this->admin())
            ->get(route('asistencia.empleados.show', $this->ana))
            ->assertOk()
            ->assertViewHas('ordenesRecientes', fn ($c) => $c->count() === 1)
            ->assertSee(MotivoFalloEnrolamiento::DedosNoCoinciden->explicacion());
    }

    /** LO CENTRAL DE LA WEB: el token del lector no aparece en la pantalla. */
    public function test_la_ficha_no_filtra_ningun_token(): void
    {
        app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);
        $orden = AsistenciaOrdenEnrolamiento::sole();
        $orden->emitirToken();

        $respuesta = $this->actingAs($this->admin())->get(route('asistencia.empleados.show', $this->ana))->assertOk();

        $respuesta->assertDontSee($this->lector->token_hash);
        $respuesta->assertDontSee($orden->refresh()->token_hash);
    }

    // ─────────────────────────── Auditoría ───────────────────────────

    /** Pedirlo y cancelarlo quedan registrados. Sin secretos. */
    public function test_las_ordenes_dejan_auditoria_sin_secretos(): void
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector);
        $orden->emitirToken();

        $actividades = Activity::query()
            ->where('subject_type', AsistenciaOrdenEnrolamiento::class)
            ->get();

        $this->assertGreaterThan(0, $actividades->count(), 'Crear una orden tiene que auditarse.');

        foreach ($actividades as $actividad) {
            $json = $actividad->properties->toJson();
            $this->assertStringNotContainsString('token_hash', $json);
            $this->assertStringNotContainsString($orden->refresh()->token_hash, $json);
        }
    }
}
