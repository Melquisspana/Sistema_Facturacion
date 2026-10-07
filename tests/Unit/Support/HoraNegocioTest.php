<?php

namespace Tests\Unit\Support;

use App\Services\Asistencia\HoraOficial;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HoraNegocioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.zona_negocio' => 'America/El_Salvador']);
        // A propósito con la aplicación en UTC (no es el valor por defecto desde el issue
        // #59): prueba que HoraNegocio no depende de app.timezone. La aplicación se vuelve
        // a crear en cada prueba y restaura la zona de la configuración.
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function test_hoy_respeta_el_dia_local_en_los_bordes_utc(): void
    {
        foreach ([
            '2026-10-03 23:59:00' => '2026-10-03',
            '2026-10-04 00:00:00' => '2026-10-03',
            '2026-10-04 05:59:59' => '2026-10-03',
            '2026-10-04 06:00:00' => '2026-10-04',
        ] as $instante => $fecha) {
            $this->travelTo(Carbon::parse($instante, 'UTC'));
            $this->assertSame($fecha, HoraNegocio::fechaHoy());
            $this->assertSame($fecha.' 00:00:00', HoraNegocio::hoy()->format('Y-m-d H:i:s'));
            $this->assertSame('America/El_Salvador', HoraNegocio::ahora()->timezoneName);
            $this->assertSame(now()->timestamp, HoraNegocio::ahora()->timestamp);
        }

        $this->travelTo(Carbon::parse('2026-10-04 00:00:00', 'UTC'));
        $this->assertSame('2026-10-04', now()->toDateString());
        $this->assertSame('2026-10-03', HoraNegocio::fechaHoy());
    }

    public function test_limites_del_dia_local_se_expresan_en_utc(): void
    {
        foreach (['2026-10-03', Carbon::parse('2026-10-04 01:00:00', 'UTC'), CarbonImmutable::parse('2026-10-04 01:00:00', 'UTC')] as $fecha) {
            $inicio = HoraNegocio::inicioDelDiaUtc($fecha);
            $fin = HoraNegocio::finDelDiaUtc($fecha);
            $this->assertSame('2026-10-03 06:00:00', $inicio->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-04 05:59:59', $fin->format('Y-m-d H:i:s'));
            $this->assertSame('UTC', $inicio->timezoneName);
            $this->assertSame('UTC', $fin->timezoneName);
            $this->assertSame(0, $fin->micro);
            if ($fecha instanceof CarbonInterface) {
                $this->assertSame('2026-10-04 01:00:00 UTC', $fecha->format('Y-m-d H:i:s e'));
            }
        }
    }

    public function test_a_local_copia_el_instante_sin_mutarlo(): void
    {
        $original = Carbon::parse('2026-10-04 01:00:00', 'UTC');
        $local = HoraNegocio::aLocal($original);
        $this->assertNotSame($original, $local);
        $this->assertSame('2026-10-03 19:00:00 America/El_Salvador', $local->format('Y-m-d H:i:s e'));
        $this->assertSame('2026-10-04 01:00:00 UTC', $original->format('Y-m-d H:i:s e'));
        $this->assertSame($original->timestamp, $local->timestamp);
    }

    public function test_zona_es_configurable_y_tiene_respaldo_si_esta_vacia(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 01:00:00', 'UTC'));
        config(['app.zona_negocio' => 'Asia/Tokyo']);
        $this->assertSame('Asia/Tokyo', HoraNegocio::zona());
        $this->assertSame('2026-10-04 10:00:00', HoraNegocio::ahora()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 15:00:00', HoraNegocio::inicioDelDiaUtc('2026-10-03')->format('Y-m-d H:i:s'));
        foreach ([null, '', '   '] as $zona) {
            config(['app.zona_negocio' => $zona]);
            $this->assertSame('America/El_Salvador', HoraNegocio::zona());
        }
    }

    public function test_asistencia_conserva_su_zona_o_usa_la_del_negocio(): void
    {
        $hora = app(HoraOficial::class);
        config(['app.zona_negocio' => 'Asia/Tokyo', 'asistencia.zona_horaria' => 'America/El_Salvador']);
        $this->assertSame('America/El_Salvador', $hora->zona());
        foreach ([null, '', '   '] as $zona) {
            config(['asistencia.zona_horaria' => $zona]);
            $this->assertSame('Asia/Tokyo', $hora->zona());
        }
    }
}
