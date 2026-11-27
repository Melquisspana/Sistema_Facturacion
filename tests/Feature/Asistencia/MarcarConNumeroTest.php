<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\NumeroParaMarcar;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/asistencia/marcar con `numero` en vez de `fingerprint_id`.
 *
 * Misma regla, mismo servicio y misma respuesta que con la huella; la marca anota
 * con qué se hizo. Lo que llega mal se rechaza antes de tocar la base.
 */
class MarcarConNumeroTest extends TestCase
{
    use RefreshDatabase;

    private const NUMERO = '4821';

    private AsistenciaDispositivo $lector;

    private AsistenciaEmpleado $empleado;

    private string $zonaPhp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zonaPhp = date_default_timezone_get();
        config(['asistencia.enabled' => true, 'asistencia.zona_horaria' => 'America/El_Salvador', 'app.timezone' => 'UTC',
            'asistencia.cooldown_segundos' => 90, 'asistencia.diferidas_max_dias' => 31]);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-11-26 14:00:00', 'UTC'));

        $this->lector = AsistenciaDispositivo::factory()->create();
        $this->empleado = AsistenciaEmpleado::factory()->create(['nombres' => 'Empleado', 'apellidos' => 'de prueba']);
        app(NumeroParaMarcar::class)->asignar($this->empleado, self::NUMERO);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zonaPhp);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function credenciales(): array
    {
        return ['X-Dispositivo' => $this->lector->codigo, 'X-Dispositivo-Token' => AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA];
    }

    private function marcar(array $cuerpo): TestResponse
    {
        return $this->postJson('/api/asistencia/marcar', $cuerpo, $this->credenciales());
    }

    public function test_marca_con_su_numero_y_queda_dicho_que_fue_con_numero(): void
    {
        $this->marcar(['numero' => self::NUMERO])
            ->assertOk()
            ->assertJson(['ok' => true, 'estado' => 'registrada', 'repetida' => false, 'diferida' => false,
                'empleado' => ['id' => $this->empleado->id, 'nombre_corto' => 'Empleado de']])
            ->assertJsonPath('marcacion.tipo', 'entrada')
            ->assertJsonStructure(['ok', 'estado', 'mensaje', 'empleado' => ['id', 'nombre', 'nombre_corto'], 'marcacion', 'repetida', 'diferida']);

        $this->assertDatabaseHas('asistencia_marcaciones', [
            'asistencia_empleado_id' => $this->empleado->id,
            'asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_huella_id' => null,
            'metodo' => 'numero',
            'origen' => 'dispositivo',
        ]);
    }

    public function test_la_huella_sigue_igual_y_anota_que_fue_con_huella(): void
    {
        AsistenciaHuella::factory()->create(['asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_empleado_id' => $this->empleado->id, 'fingerprint_id' => 5, 'activo' => true]);

        $this->marcar(['fingerprint_id' => 5])
            ->assertOk()
            ->assertJson(['ok' => true, 'estado' => 'registrada', 'repetida' => false, 'diferida' => false]);

        $this->assertDatabaseHas('asistencia_marcaciones', ['asistencia_empleado_id' => $this->empleado->id, 'metodo' => 'huella']);
        $this->marcar(['fingerprint_id' => 99])->assertNotFound()->assertJsonPath('estado', 'huella_desconocida')->assertJsonPath('fingerprint_id', 99);
    }

    public function test_numero_desconocido_responde_como_la_huella_desconocida_y_se_anota_sin_los_digitos(): void
    {
        Log::spy();

        $respuesta = $this->marcar(['numero' => '7395'])
            ->assertNotFound()
            ->assertJson(['ok' => false, 'estado' => 'numero_desconocido', 'repetida' => false, 'diferida' => false]);

        $this->assertStringNotContainsString('7395', $respuesta->getContent());
        $this->assertDatabaseCount('asistencia_marcaciones', 0);

        Log::shouldHaveReceived('warning')->withArgs(function (string $mensaje, array $contexto = []) {
            return str_contains($mensaje, 'número')
                && ! str_contains($mensaje.json_encode($contexto), '7395')
                && ($contexto['dispositivo'] ?? null) === $this->lector->codigo;
        })->once();
    }

    public function test_el_numero_de_una_persona_inactiva_no_marca(): void
    {
        $this->empleado->update(['activo' => false]);

        $this->marcar(['numero' => self::NUMERO])->assertForbidden()->assertJsonPath('estado', 'empleado_inactivo');
        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_la_marca_guardada_sin_red_se_reenvia_sin_duplicarse(): void
    {
        $epoch = Carbon::parse('2026-11-26 07:02:00', 'America/El_Salvador')->getTimestamp();

        $primera = $this->marcar(['numero' => self::NUMERO, 'marcado_en' => $epoch])
            ->assertOk()->assertJsonPath('diferida', true)->assertJsonPath('repetida', false)->assertJsonPath('marcacion.hora', '07:02:00');
        $this->marcar(['numero' => self::NUMERO, 'marcado_en' => $epoch])
            ->assertOk()->assertJsonPath('repetida', true)->assertJsonPath('marcacion.id', $primera->json('marcacion.id'));

        $this->assertDatabaseCount('asistencia_marcaciones', 1);
        $this->assertDatabaseHas('asistencia_marcaciones', ['origen' => 'diferida', 'metodo' => 'numero']);
    }

    public function test_huella_y_numero_comparten_la_ventana_de_cortesia(): void
    {
        AsistenciaHuella::factory()->create(['asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_empleado_id' => $this->empleado->id, 'fingerprint_id' => 5, 'activo' => true]);

        $this->marcar(['numero' => self::NUMERO])->assertOk();
        $this->marcar(['fingerprint_id' => 5])->assertStatus(409)->assertJsonPath('estado', 'cooldown');
        $this->assertSame(1, AsistenciaMarcacion::query()->count());
    }

    public function test_rechaza_numeros_mal_formados_sin_escribir_nada(): void
    {
        foreach (['48 21', '482', '48210', 4821, '48a1', '٤٨٢١', '', null, true, ['4821'], '4821.0', '+482'] as $malo) {
            $this->marcar(['numero' => $malo])
                ->assertStatus(422)
                ->assertJson(['ok' => false, 'estado' => 'payload_invalido', 'repetida' => false]);
        }

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_exige_exactamente_uno_de_los_dos(): void
    {
        $this->marcar(['numero' => self::NUMERO, 'fingerprint_id' => 5])->assertStatus(422)->assertJsonPath('estado', 'payload_invalido');
        $this->marcar([])->assertStatus(422)->assertJsonPath('estado', 'payload_invalido');
        $this->marcar(['marcado_en' => now()->timestamp])->assertStatus(422);

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_sin_credencial_de_lector_no_marca(): void
    {
        $this->postJson('/api/asistencia/marcar', ['numero' => self::NUMERO])
            ->assertUnauthorized()->assertJsonPath('estado', 'dispositivo_no_autorizado');
        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_el_numero_de_otra_persona_marca_a_esa_persona(): void
    {
        $otra = AsistenciaEmpleado::factory()->create(['nombres' => 'Otra', 'apellidos' => 'de prueba']);
        app(NumeroParaMarcar::class)->asignar($otra, '1357');

        $this->marcar(['numero' => '1357'])->assertOk()->assertJsonPath('empleado.id', $otra->id);
        $this->assertDatabaseHas('asistencia_marcaciones', ['asistencia_empleado_id' => $otra->id, 'metodo' => 'numero']);
        $this->assertDatabaseMissing('asistencia_marcaciones', ['asistencia_empleado_id' => $this->empleado->id]);
    }
}
