<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\User;
use App\Support\Asistencia\ConsultaAsistencia;
use App\Support\Asistencia\FiltroAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MarcacionDiferidaTest extends TestCase
{
    use RefreshDatabase;

    private string $zonaPhp;

    private AsistenciaDispositivo $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zonaPhp = date_default_timezone_get();
        config(['asistencia.enabled' => true, 'asistencia.zona_horaria' => 'America/El_Salvador', 'app.timezone' => 'UTC', 'asistencia.cooldown_segundos' => 90, 'asistencia.diferidas_max_dias' => 31]);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-10-29 18:00:00', 'UTC'));
        $this->lector = AsistenciaDispositivo::factory()->create(['token_hash' => AsistenciaDispositivo::hashDeToken('token-prueba-diferida')]);
        AsistenciaHuella::factory()->create(['asistencia_dispositivo_id' => $this->lector->id, 'asistencia_empleado_id' => AsistenciaEmpleado::factory()->create()->id, 'fingerprint_id' => 1, 'activo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zonaPhp);
        parent::tearDown();
    }

    private function marcar(array $datos = []): TestResponse
    {
        return $this->postJson('/api/asistencia/marcar', ['fingerprint_id' => 1] + $datos, ['X-Dispositivo' => $this->lector->codigo, 'X-Dispositivo-Token' => 'token-prueba-diferida']);
    }

    private function diferida(string $local): TestResponse
    {
        return $this->marcar(['marcado_en' => Carbon::parse($local, 'America/El_Salvador')->getTimestamp()]);
    }

    public function test_sin_epoch_conserva_el_reloj_y_origen_del_servidor(): void
    {
        $this->marcar()->assertOk()->assertJsonPath('repetida', false)->assertJsonPath('diferida', false)->assertJsonPath('marcacion.hora', '12:00:00');
        $this->assertDatabaseHas('asistencia_marcaciones', ['origen' => 'dispositivo', 'marcado_at' => '2026-10-29 18:00:00']);
    }

    public function test_reenvio_es_idempotente_antes_del_cooldown(): void
    {
        $primera = $this->diferida('2026-10-28 08:00:00')->assertOk()->assertJsonPath('repetida', false)->assertJsonPath('diferida', true);
        $this->diferida('2026-10-28 08:00:00')->assertOk()->assertJsonPath('repetida', true)->assertJsonPath('marcacion.id', $primera->json('marcacion.id'));
        $this->assertDatabaseCount('asistencia_marcaciones', 1);
    }

    public function test_valida_limites_y_tipo_del_epoch(): void
    {
        foreach ([now()->timestamp + 301, now()->timestamp - 31 * 86400 - 1, 'ayer', 1.5, null, true, []] as $epoch) {
            $this->marcar(['marcado_en' => $epoch])->assertStatus(422)->assertJsonPath('estado', 'hora_invalida')->assertJsonPath('ok', false)->assertJsonStructure(['mensaje', 'errores']);
        }
        $this->assertDatabaseCount('asistencia_marcaciones', 0);
        foreach ([now()->timestamp + 299, now()->timestamp - 30 * 86400, now()->timestamp + 300, now()->timestamp - 31 * 86400] as $epoch) {
            config(['asistencia.cooldown_segundos' => 0]);
            $this->marcar(['marcado_en' => $epoch])->assertOk();
        }
        config(['asistencia.diferidas_max_dias' => 2]);
        $this->marcar(['marcado_en' => now()->timestamp - 3 * 86400])->assertStatus(422)->assertJsonPath('estado', 'hora_invalida');
    }

    public function test_alterna_por_dia_local_incluso_con_fecha_utc_distinta(): void
    {
        foreach (['08:00:00' => 'entrada', '12:00:00' => 'salida', '13:00:00' => 'entrada', '23:50:00' => 'salida'] as $hora => $tipo) {
            $this->diferida('2026-10-28 '.$hora)->assertOk()->assertJsonPath('marcacion.tipo', $tipo)->assertJsonPath('marcacion.fecha', '2026-10-28');
        }
        $this->assertDatabaseHas('asistencia_marcaciones', ['marcado_at' => '2026-10-29 05:50:00', 'origen' => 'diferida']);
        $this->assertSame('2026-10-28', AsistenciaMarcacion::query()->latest('marcado_at')->first()->fecha_local->format('Y-m-d'));
        $this->diferida('2026-10-29 00:10:00')->assertOk()->assertJsonPath('marcacion.tipo', 'entrada');
    }

    public function test_tipo_usa_la_anterior_del_dia_y_no_una_posterior(): void
    {
        $this->diferida('2026-10-28 08:00:00')->assertOk();
        $this->diferida('2026-10-28 13:00:00')->assertOk();
        $this->diferida('2026-10-28 12:00:00')->assertOk()->assertJsonPath('marcacion.tipo', 'salida');
        $this->diferida('2026-10-28 07:00:00')->assertOk()->assertJsonPath('marcacion.tipo', 'entrada');
    }

    public function test_el_mismo_segundo_de_otra_persona_no_es_un_reenvio(): void
    {
        $this->diferida('2026-10-28 08:00:00')->assertOk();
        AsistenciaHuella::query()->where('asistencia_dispositivo_id', $this->lector->id)
            ->update(['asistencia_empleado_id' => AsistenciaEmpleado::factory()->create()->id]);
        $this->diferida('2026-10-28 08:00:00')->assertOk()->assertJsonPath('repetida', false);
        $this->assertDatabaseCount('asistencia_marcaciones', 2);
    }

    public function test_guarda_el_epoch_en_la_zona_de_guardado(): void
    {
        config(['app.timezone' => 'America/El_Salvador']);
        date_default_timezone_set('America/El_Salvador');
        $this->diferida('2026-10-28 23:50:00')->assertOk();
        $this->assertDatabaseHas('asistencia_marcaciones', ['marcado_at' => '2026-10-28 23:50:00']);
        $this->assertSame('2026-10-28', AsistenciaMarcacion::query()->sole()->fecha_local->format('Y-m-d'));
        $this->diferida('2026-10-28 23:50:00')->assertOk()->assertJsonPath('repetida', true);
        $this->assertDatabaseCount('asistencia_marcaciones', 1);
    }

    public function test_cooldown_revisa_ambos_vecinos_incluso_otro_lector(): void
    {
        $this->diferida('2026-10-28 08:00:00')->assertOk();
        $this->diferida('2026-10-28 08:00:30')->assertStatus(409)->assertJsonPath('estado', 'cooldown');
        $this->diferida('2026-10-28 07:59:30')->assertStatus(409)->assertJsonPath('estado', 'cooldown');
        AsistenciaMarcacion::query()->update(['asistencia_dispositivo_id' => AsistenciaDispositivo::factory()->create()->id]);
        $this->diferida('2026-10-28 08:00:00')->assertStatus(409)->assertJsonPath('estado', 'cooldown');
        $this->assertDatabaseCount('asistencia_marcaciones', 1);
    }

    public function test_historial_y_filtro_reconocen_el_origen_diferida(): void
    {
        $this->diferida('2026-10-28 08:00:00')->assertOk();
        $this->marcar()->assertOk();
        $filtro = FiltroAsistencia::desdeArray(['origen' => 'diferida']);
        $this->assertSame('diferida', $filtro->origen);
        $this->assertSame(1, app(ConsultaAsistencia::class)->query($filtro)->count());
        $usuario = User::factory()->create(['activo' => true])->givePermissionTo('asistencia.ver');
        $this->actingAs($usuario)->get(route('asistencia.marcaciones.index', ['desde' => '2026-10-28', 'hasta' => '2026-10-29']))->assertOk()->assertSee('Lector (sin red)')->assertSee($this->lector->nombre);
        $this->get(route('asistencia.marcaciones.index', ['desde' => '2026-10-28', 'hasta' => '2026-10-29', 'origen' => 'diferida']))
            ->assertOk()->assertSessionHasNoErrors()->assertViewHas('filtro', fn ($f) => $f->origen === 'diferida');
    }
}
