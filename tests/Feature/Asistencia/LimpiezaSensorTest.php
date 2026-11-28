<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Models\User;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Limpieza de las huellas SUELTAS del sensor: plantillas grabadas en el AS608 que
 * el sistema no conoce, porque sus filas se borraron de la base.
 *
 * Se dispara a mano y en dos pasos: el comando las muestra, `--confirmar` las deja
 * pendientes y el lector las borra la próxima vez que manda su índice. Nada borra
 * solo.
 *
 * Sensor de partida, ranuras ocupadas 3, 5, 7, 9 y 11:
 *   3  -> asignada a alguien (fila activa)
 *   5  -> dada de baja (fila liberada: tiene historial)
 *   7  -> reservada por una orden abierta
 *   9  -> suelta
 *   11 -> suelta
 */
class LimpiezaSensorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA;

    private const OCUPADAS = [3, 5, 7, 9, 11];

    private AsistenciaDispositivo $lector;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);
        $this->responsable = User::factory()->create(['activo' => true, 'email' => 'responsable@example.test']);

        AsistenciaHuella::factory()->enRanura(3)->create(['asistencia_dispositivo_id' => $this->lector->id]);
        AsistenciaHuella::factory()->enRanura(5)->liberada()->create(['asistencia_dispositivo_id' => $this->lector->id]);
        AsistenciaOrdenEnrolamiento::factory()->enRanura(7)->create(['asistencia_dispositivo_id' => $this->lector->id]);

        $this->lector->sincronizarIndice(162, self::OCUPADAS);
    }

    private function comoElLector(?AsistenciaDispositivo $lector = null): static
    {
        return $this->withHeaders([
            'X-Dispositivo' => ($lector ?? $this->lector)->codigo,
            'X-Dispositivo-Token' => self::TOKEN,
        ]);
    }

    private function mandarIndice(array $ocupadas = self::OCUPADAS, ?AsistenciaDispositivo $lector = null)
    {
        return $this->comoElLector($lector)
            ->postJson('/api/asistencia/enrolamiento/indice-sensor', ['capacidad' => 162, 'ocupadas' => $ocupadas])
            ->assertOk();
    }

    private function confirmar(string $codigo = 'lector-entrada'): void
    {
        $this->artisan('asistencia:limpiar-sensor', [
            'codigo' => $codigo,
            '--confirmar' => true,
            '--usuario' => $this->responsable->email,
        ])->assertSuccessful();
    }

    // ═══════════════════════ EL COMANDO MUESTRA ═══════════════════════

    public function test_sin_opciones_solo_muestra_las_sueltas_y_las_de_baja_aparte(): void
    {
        $this->artisan('asistencia:limpiar-sensor', ['codigo' => 'lector-entrada'])
            ->expectsOutput('Huellas sueltas (2): 9, 11')
            ->expectsOutput('Dadas de baja, no se borran (1): 5')
            ->assertSuccessful();

        // Solo mostró: ni pendiente ni auditoría.
        $this->mandarIndice()->assertJsonMissingPath('borrar');
        $this->assertSame(0, Activity::query()->where('event', 'limpieza_sensor')->count());
    }

    public function test_un_lector_que_no_existe_se_dice_y_no_hace_nada(): void
    {
        $this->artisan('asistencia:limpiar-sensor', ['codigo' => 'no-existe'])
            ->expectsOutputToContain('No hay ningún lector con el código «no-existe»')
            ->assertFailed();
    }

    // ═══════════════════════ CONFIRMAR ═══════════════════════

    public function test_confirmar_deja_la_limpieza_pendiente_y_la_audita(): void
    {
        $this->confirmar();

        $auditoria = Activity::query()->where('event', 'limpieza_sensor')->sole();
        $this->assertSame('asistencia', $auditoria->log_name);
        $this->assertTrue($auditoria->subject->is($this->lector));
        $this->assertTrue($auditoria->causer->is($this->responsable));
        $this->assertSame('lector-entrada', $auditoria->properties['codigo']);
        $this->assertSame([9, 11], $auditoria->properties['ranuras']);

        // Las ranuras con fila (3 activa, 5 de baja) y la reservada (7) nunca salen.
        $this->mandarIndice()->assertJsonPath('borrar', [9, 11]);
    }

    public function test_confirmar_sin_responsable_no_deja_nada_pendiente(): void
    {
        $this->artisan('asistencia:limpiar-sensor', ['codigo' => 'lector-entrada', '--confirmar' => true])
            ->expectsOutputToContain('--usuario')
            ->assertFailed();

        $this->mandarIndice()->assertJsonMissingPath('borrar');
        $this->assertSame(0, Activity::query()->where('event', 'limpieza_sensor')->count());
    }

    public function test_con_un_indice_de_mas_de_un_dia_pide_reiniciar_el_lector_y_no_confirma(): void
    {
        $this->travel(2)->days();

        $this->artisan('asistencia:limpiar-sensor', ['codigo' => 'lector-entrada'])
            ->expectsOutputToContain('Reiniciá el lector')
            ->assertSuccessful();

        $this->artisan('asistencia:limpiar-sensor', [
            'codigo' => 'lector-entrada', '--confirmar' => true, '--usuario' => $this->responsable->email,
        ])->expectsOutputToContain('Reiniciá el lector')->assertFailed();

        $this->mandarIndice()->assertJsonMissingPath('borrar');
    }

    // ═══════════════════════ LA RESPUESTA DEL ÍNDICE ═══════════════════════

    /** Nada borra solo: sin una confirmación, la respuesta nunca trae `borrar`. */
    public function test_el_indice_trae_borrar_solo_con_una_limpieza_pendiente(): void
    {
        $this->mandarIndice()->assertJsonMissingPath('borrar');

        $this->confirmar();

        $this->mandarIndice()->assertJsonPath('borrar', [9, 11]);
    }

    /** Entre la confirmación y el índice alguien registró la 9 y se reservó la 11. */
    public function test_el_recalculo_saca_las_ranuras_que_entretanto_se_registraron_o_reservaron(): void
    {
        $this->confirmar();

        AsistenciaHuella::factory()->enRanura(9)->create([
            'asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_empleado_id' => AsistenciaEmpleado::factory(),
        ]);

        $this->mandarIndice()->assertJsonPath('borrar', [11]);

        AsistenciaOrdenEnrolamiento::query()->delete();
        AsistenciaOrdenEnrolamiento::factory()->enRanura(11)->create(['asistencia_dispositivo_id' => $this->lector->id]);

        $this->mandarIndice()->assertJsonMissingPath('borrar');
    }

    public function test_la_pendiente_se_borra_sola_y_se_audita_cuando_el_indice_ya_no_las_tiene(): void
    {
        $this->confirmar();

        // El lector borró la 9 y la 11 y vuelve a mandar su índice.
        $this->mandarIndice([3, 5, 7])->assertJsonMissingPath('borrar');

        $hecha = Activity::query()->where('event', 'limpieza_sensor_hecha')->sole();
        $this->assertTrue($hecha->subject->is($this->lector));
        $this->assertSame([9, 11], $hecha->properties['borradas']);

        // Ya no hay pendiente: si las ranuras volvieran a aparecer, no se piden.
        $this->mandarIndice()->assertJsonMissingPath('borrar');
        $this->assertSame(1, Activity::query()->where('event', 'limpieza_sensor_hecha')->count());
    }

    public function test_otro_lector_no_recibe_la_lista(): void
    {
        $otro = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-bodega']);

        $this->confirmar();

        // El otro sensor tiene sueltas en las MISMAS ranuras, pero nadie confirmó nada para él.
        $this->mandarIndice([9, 11], $otro)->assertJsonMissingPath('borrar');
        $this->mandarIndice()->assertJsonPath('borrar', [9, 11]);
    }

    public function test_sin_token_de_lector_el_indice_responde_401(): void
    {
        $this->confirmar();

        $this->postJson('/api/asistencia/enrolamiento/indice-sensor', ['capacidad' => 162, 'ocupadas' => self::OCUPADAS])
            ->assertUnauthorized()
            ->assertJsonMissingPath('borrar');

        $this->withHeaders(['X-Dispositivo' => 'lector-entrada', 'X-Dispositivo-Token' => 'token-equivocado'])
            ->postJson('/api/asistencia/enrolamiento/indice-sensor', ['capacidad' => 162, 'ocupadas' => self::OCUPADAS])
            ->assertUnauthorized()
            ->assertJsonMissingPath('borrar');
    }
}
