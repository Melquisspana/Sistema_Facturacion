<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Services\Dte\ValidadorReglasInvalidacion;
use App\Support\Dte\PlazoInvalidacion;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlazoInvalidacionTest extends TestCase
{
    use RefreshDatabase;

    public static function fechas(): array
    {
        return [
            ['03', '2026-04-25', ['2026' => ['2026-05-01']], '2026-05-15'],
            ['03', '2025-10-31', [], '2025-11-14'],
            ['03', '2026-04-15', ['2026' => ['2026-05-01']], '2026-05-15'],
            ['03', '2026-03-19', ['2026' => ['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04', '2026-04-05', '2026-04-06']], '2026-04-20'],
            ['01', '2025-11-11', [], '2026-02-11'],
            ['01', '2025-11-25', [], '2026-02-25'],
            ['01', '2025-11-30', [], '2026-02-28'],
            ['01', '2026-01-31', [], '2026-04-30'],
            ['03', '2026-01-20', [], '2026-02-13'],
            ['03', '2026-04-25', [], '2026-05-14'],
            ['03', '2025-12-15', ['2026' => ['2026-01-01']], '2026-01-15'],
            ['01', '2025-11-15', [], '2026-02-15'],
            ['11', '2025-11-30', [], '2026-02-28'],
            ['05', '2026-04-25', ['2026' => ['2026-05-01']], '2026-05-15'],
            ['06', '2026-04-25', ['2026' => ['2026-05-01']], '2026-05-15'],
        ];
    }

    #[DataProvider('fechas')]
    public function test_limite_del_manual_y_bordes(string $tipo, string $sello, array $calendario, string $limite): void
    {
        config(['dte.invalidacion.dias_inhabiles' => $calendario]);
        $resultado = app(PlazoInvalidacion::class)->limite(TipoDte::from($tipo), $sello);
        $this->assertSame('UTC', $resultado->timezoneName);
        $this->assertEquals(HoraNegocio::finDelDiaUtc($limite), $resultado);
    }

    private function problemas(?string $sello = '2026-04-25 12:00:00'): array
    {
        // Sin persistir; la base en memoria solo atiende la consulta de dependencias.
        $dte = new Dte(['tipo_dte' => TipoDte::CreditoFiscal, 'fecha_procesamiento_mh' => $sello, 'fecha_emision' => '2026-03-01']);

        return app(ValidadorReglasInvalidacion::class)->problemas($dte, new EventoInvalidacionData(tipoAnulacion: TipoAnulacionMh::RescindirOperacion));
    }

    public function test_instante_real_y_ultimo_segundo_local(): void
    {
        config(['dte.invalidacion.dias_inhabiles' => ['2026' => ['2026-05-01']]]);
        foreach (['2026-05-16 04:00:00 UTC', '2026-05-15 23:59:59 America/El_Salvador'] as $instante) {
            $this->travelTo(Carbon::parse($instante));
            $this->assertSame([], $this->problemas());
        }
        $this->travelTo(Carbon::parse('2026-05-16 00:00:00', 'America/El_Salvador'));
        $this->assertStringContainsString('15/05/2026 a las 23:59:59', implode(' ', $this->problemas()));
    }

    public function test_calendario_ausente_y_tabla_vacia_explicita(): void
    {
        $this->travelTo(Carbon::parse('2026-05-16', 'America/El_Salvador'));
        config(['dte.invalidacion.dias_inhabiles' => []]);
        $this->assertStringContainsString('(calculado sin calendario de días inhábiles 2026; cargarlo en config/dte.php)', implode(' ', $this->problemas()));
        config(['dte.invalidacion.dias_inhabiles' => ['2026' => []]]);
        $this->assertStringNotContainsString('sin calendario', implode(' ', $this->problemas()));
    }

    public function test_sello_local_marcado_utc_no_se_convierte_y_generacion_no_decide(): void
    {
        config(['dte.invalidacion.dias_inhabiles' => ['2026' => ['2026-05-01']]]);
        $this->travelTo(Carbon::parse('2026-05-16', 'America/El_Salvador'));
        $this->assertStringContainsString('30/04/2026', implode(' ', $this->problemas('2026-04-30 23:30:00')));
        $this->assertStringContainsString('15/05/2026', implode(' ', $this->problemas('2026-04-15 12:00:00')));
        $this->assertSame([], $this->problemas(null));
    }

    public static function calendariosInvalidos(): array
    {
        return [[['2026' => ['2026-02-30']]], [['2026' => ['01/05/2026']]], [['2026' => ['2025-05-01']]], [['2026' => '2026-05-01']], [['2026' => [null]]]];
    }

    #[DataProvider('calendariosInvalidos')]
    public function test_configuracion_invalida_no_se_ignora(array $calendario): void
    {
        config(['dte.invalidacion.dias_inhabiles' => $calendario]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuración inválida');
        app(PlazoInvalidacion::class)->limite(TipoDte::CreditoFiscal, '2026-04-25');
    }
}
