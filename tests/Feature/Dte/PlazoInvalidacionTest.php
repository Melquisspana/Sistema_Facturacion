<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Facades\Ajustes;
use App\Models\Dte;
use App\Services\Dte\ValidadorReglasInvalidacion;
use App\Support\Dte\CalendarioInhabiles;
use App\Support\Dte\PlazoInvalidacion;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlazoInvalidacionTest extends TestCase
{
    use RefreshDatabase;

    /** Calendario `null`: el oficial 2026 que trae config/dte.php (Calendario Tributario 2026 del MH). */
    public static function fechas(): array
    {
        return [
            ['03', '2026-04-25', ['2026' => ['2026-05-01']], '2026-05-15'],
            ['03', '2025-10-31', [], '2025-11-14'],
            // Los dos ejemplos del Manual Funcional, con el calendario oficial.
            ['03', '2026-04-15', null, '2026-05-15'],
            ['03', '2026-03-19', null, '2026-04-20'],
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
    public function test_limite_del_manual_y_bordes(string $tipo, string $sello, ?array $calendario, string $limite): void
    {
        if ($calendario !== null) {
            config(['dte.invalidacion.dias_inhabiles' => $calendario]);
        }
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
        $this->assertStringContainsString('(calculado sin el calendario completo de días inhábiles de 2026; cargalo en Configuración)', implode(' ', $this->problemas()));
        config(['dte.invalidacion.dias_inhabiles' => ['2026' => []]]);
        $this->assertStringNotContainsString('calendario completo', implode(' ', $this->problemas()));
    }

    // ------------------------------------------------ calendario cargado en Configuración

    /** @param  array<int, string>  $fechas */
    private function cargarEnConfiguracion(array $fechas, array $completos = []): void
    {
        Ajustes::guardarComoSistema(CalendarioInhabiles::FECHAS, $fechas);
        Ajustes::guardarComoSistema(CalendarioInhabiles::COMPLETOS, $completos);
    }

    public function test_el_plazo_usa_lo_cargado_en_configuracion(): void
    {
        config(['dte.invalidacion.dias_inhabiles' => []]);
        $this->cargarEnConfiguracion(['2026-05-01'], ['2026']);
        $plazo = app(PlazoInvalidacion::class);

        $this->assertEquals(HoraNegocio::finDelDiaUtc('2026-05-15'), $plazo->limite(TipoDte::CreditoFiscal, '2026-04-25'));
        $this->assertTrue($plazo->calendarioCompleto(TipoDte::CreditoFiscal, '2026-04-25'));
        $this->assertNull($plazo->avisoCalendario(TipoDte::CreditoFiscal, '2026-04-25'));
    }

    /** Para su año manda Configuración, aunque el archivo diga otra cosa. */
    public function test_configuracion_manda_sobre_el_archivo_en_su_anio(): void
    {
        config(['dte.invalidacion.dias_inhabiles' => ['2026' => ['2026-05-01']]]);
        $this->cargarEnConfiguracion([], ['2026']);

        $this->assertEquals(HoraNegocio::finDelDiaUtc('2026-05-14'), app(PlazoInvalidacion::class)->limite(TipoDte::CreditoFiscal, '2026-04-25'));
    }

    /** Un año que Configuración no tiene sale del archivo (el oficial 2026). */
    public function test_el_archivo_cubre_el_anio_que_configuracion_no_tiene(): void
    {
        $this->cargarEnConfiguracion(['2027-01-01'], ['2027']);
        $plazo = app(PlazoInvalidacion::class);

        $this->assertEquals(HoraNegocio::finDelDiaUtc('2026-04-20'), $plazo->limite(TipoDte::CreditoFiscal, '2026-03-19'));
        $this->assertTrue($plazo->calendarioCompleto(TipoDte::CreditoFiscal, '2026-03-19'));
    }

    /** Fechas cargadas sin marcar el año completo: se usan, pero se avisa. */
    public function test_anio_cargado_sin_marcar_completo_avisa(): void
    {
        config(['dte.invalidacion.dias_inhabiles' => []]);
        $this->cargarEnConfiguracion(['2026-05-01']);
        $plazo = app(PlazoInvalidacion::class);

        $this->assertEquals(HoraNegocio::finDelDiaUtc('2026-05-15'), $plazo->limite(TipoDte::CreditoFiscal, '2026-04-25'));
        $this->assertSame(
            '(calculado sin el calendario completo de días inhábiles de 2026; cargalo en Configuración)',
            $plazo->avisoCalendario(TipoDte::CreditoFiscal, '2026-04-25'),
        );
    }

    public function test_sin_calendario_en_ningun_lado_avisa_y_cuenta_lunes_a_viernes(): void
    {
        config(['dte.invalidacion.dias_inhabiles' => []]);
        $plazo = app(PlazoInvalidacion::class);

        $this->assertEquals(HoraNegocio::finDelDiaUtc('2027-05-14'), $plazo->limite(TipoDte::CreditoFiscal, '2027-04-25'));
        $this->assertStringContainsString('cargalo en Configuración', (string) $plazo->avisoCalendario(TipoDte::CreditoFiscal, '2027-04-25'));
        // La factura cuenta tres meses: no depende del calendario.
        $this->assertNull($plazo->avisoCalendario(TipoDte::Factura, '2027-04-25'));
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
