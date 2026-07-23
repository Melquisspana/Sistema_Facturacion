<?php

namespace Tests\Feature\Support;

use App\Models\RespaldoEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fuente de verdad de "backup del día listo": un registro real en BD, no un escaneo
 * de archivos por fecha de modificación (que tenía un bug de timezone documentado en
 * el código anterior). `hayValidoHoy()` compara contra "hoy" en `app.timezone`.
 */
class RespaldoEjecucionTest extends TestCase
{
    use RefreshDatabase;

    private function crear(bool $exitoso, Carbon $terminadoEn): RespaldoEjecucion
    {
        return RespaldoEjecucion::create([
            'iniciado_en' => $terminadoEn->copy()->subMinute(),
            'terminado_en' => $terminadoEn,
            'exitoso' => $exitoso,
            'archivo_ruta' => $exitoso ? 'auto-test.sql' : null,
            'archivo_tamano_bytes' => $exitoso ? 1234 : null,
            'sha256' => $exitoso ? str_repeat('a', 64) : null,
            'mensaje' => $exitoso ? 'ok' : 'fallo simulado',
            'origen' => 'automatico',
        ]);
    }

    public function test_hay_valido_hoy_con_backup_exitoso_de_hoy(): void
    {
        $this->crear(true, Carbon::now(config('app.timezone')));

        $this->assertTrue(RespaldoEjecucion::hayValidoHoy());
    }

    public function test_no_hay_valido_hoy_si_el_ultimo_exitoso_fue_ayer(): void
    {
        $this->crear(true, Carbon::now(config('app.timezone'))->subDay());

        $this->assertFalse(RespaldoEjecucion::hayValidoHoy());
    }

    public function test_no_hay_valido_hoy_si_el_de_hoy_fallo(): void
    {
        $this->crear(false, Carbon::now(config('app.timezone')));

        $this->assertFalse(RespaldoEjecucion::hayValidoHoy());
    }

    public function test_hay_valido_hoy_si_hay_uno_fallido_y_otro_exitoso_el_mismo_dia(): void
    {
        $hoy = Carbon::now(config('app.timezone'));
        $this->crear(false, $hoy->copy()->subHours(3));
        $this->crear(true, $hoy);

        $this->assertTrue(RespaldoEjecucion::hayValidoHoy());
    }

    public function test_ultima_devuelve_la_mas_reciente_por_terminado_en_sin_importar_el_resultado(): void
    {
        $hoy = Carbon::now(config('app.timezone'));
        $this->crear(true, $hoy->copy()->subDay());
        $masReciente = $this->crear(false, $hoy);

        $this->assertSame($masReciente->id, RespaldoEjecucion::ultima()->id);
    }

    public function test_ultima_es_null_sin_registros(): void
    {
        $this->assertNull(RespaldoEjecucion::ultima());
    }
}
