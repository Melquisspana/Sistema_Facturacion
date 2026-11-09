<?php

namespace Tests\Feature\Asistencia;

use App\Facades\Ajustes;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Los dos ajustes del lector de huella que pasaron a Configuración › Módulos ›
 * Asistencia, probados por la API real del lector (MarcarRequest y RegistrarMarcacion).
 * Todo con instantes absolutos (epoch) y la zona fijada en la prueba: no dependen de la
 * hora ni de la zona de la PC. Datos ficticios.
 */
class AjustesLectorTest extends TestCase
{
    use RefreshDatabase;

    private string $zonaPhp;

    private AsistenciaDispositivo $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zonaPhp = date_default_timezone_get();
        config(['asistencia.enabled' => true, 'asistencia.zona_horaria' => 'America/El_Salvador', 'app.timezone' => 'UTC',
            'asistencia.cooldown_segundos' => 90, 'asistencia.diferidas_max_dias' => 31]);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-10-29 18:00:00', 'UTC'));
        $this->lector = AsistenciaDispositivo::factory()->create(['token_hash' => AsistenciaDispositivo::hashDeToken('token-prueba-ajustes')]);
        AsistenciaHuella::factory()->create(['asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_empleado_id' => AsistenciaEmpleado::factory()->create()->id, 'fingerprint_id' => 1, 'activo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zonaPhp);
        parent::tearDown();
    }

    private function marcarHace(int $segundos): TestResponse
    {
        return $this->postJson('/api/asistencia/marcar', ['fingerprint_id' => 1, 'marcado_en' => now()->getTimestamp() - $segundos],
            ['X-Dispositivo' => $this->lector->codigo, 'X-Dispositivo-Token' => 'token-prueba-ajustes']);
    }

    public function test_la_espera_entre_marcas_sale_del_env_y_luego_de_lo_guardado(): void
    {
        // .env: 90 s. Dos marcas a 30 s: la segunda no cuenta.
        $this->marcarHace(3600)->assertOk();
        $this->marcarHace(3570)->assertStatus(409)->assertJsonPath('estado', 'cooldown');
        $this->assertDatabaseCount('asistencia_marcaciones', 1);

        // Guardado: 20 s. La misma separación ya cuenta.
        Ajustes::guardarComoSistema('asistencia.cooldown_segundos', 20);
        $this->marcarHace(3570)->assertOk();
        $this->assertDatabaseCount('asistencia_marcaciones', 2);

        // Guardado más largo que el .env: 300 s frena lo que el .env dejaba pasar.
        Ajustes::guardarComoSistema('asistencia.cooldown_segundos', 300);
        $this->marcarHace(3450)->assertStatus(409)->assertJsonPath('estado', 'cooldown');
        $this->assertDatabaseCount('asistencia_marcaciones', 2);
    }

    public function test_los_dias_de_marcas_sin_red_salen_del_env_y_luego_de_lo_guardado(): void
    {
        $tresDias = 3 * 86400;

        // .env: 31 días. Una marca de hace tres días entra.
        $this->marcarHace($tresDias)->assertOk();

        // Guardado: 2 días. La de hace tres se rechaza; la de ayer, no.
        Ajustes::guardarComoSistema('asistencia.diferidas_max_dias', 2);
        $this->marcarHace($tresDias + 600)->assertStatus(422)->assertJsonPath('estado', 'hora_invalida');
        $this->marcarHace(86400)->assertOk();

        // Guardado: 40 días, más que el .env. Una de hace 35 días entra.
        Ajustes::guardarComoSistema('asistencia.diferidas_max_dias', 40);
        $this->marcarHace(35 * 86400)->assertOk();
        $this->assertDatabaseCount('asistencia_marcaciones', 3);
    }

    /**
     * Un .env fuera del rango de la pantalla (900 s, 90 días) no frena las marcas: el
     * lector sigue usándolo tal cual, como antes de que existiera la pantalla.
     */
    public function test_un_env_fuera_de_rango_no_frena_las_marcas(): void
    {
        config(['asistencia.cooldown_segundos' => 900, 'asistencia.diferidas_max_dias' => 90]);

        // 61 días atrás: fuera de los 60 de la pantalla, dentro de los 90 del .env.
        $this->marcarHace(61 * 86400)->assertOk();

        // 900 s del .env: otra marca a los 300 s no cuenta.
        $this->marcarHace(3600)->assertOk();
        $this->marcarHace(3300)->assertStatus(409)->assertJsonPath('estado', 'cooldown');
        $this->assertDatabaseCount('asistencia_marcaciones', 2);

        // Lo guardado en pantalla tapa ese .env.
        Ajustes::guardarComoSistema('asistencia.cooldown_segundos', 60);
        $this->marcarHace(3300)->assertOk();
        $this->assertDatabaseCount('asistencia_marcaciones', 3);
    }
}
