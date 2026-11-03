<?php

namespace Tests\Unit\Planilla;

use App\Models\Planilla\Planilla;
use App\Services\Planilla\DescuentosLey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Recálculo de renta de junio y diciembre (Decreto 10, Art. 1 lit. f).
 *
 * Como en {@see DescuentosLeyTest}, los esperados van escritos a mano con la cuenta al
 * lado, y caen en el borde de cada tramo de la tabla semestral y de la anual.
 */
class RecalculoRentaTest extends TestCase
{
    private const JUNIO = '2026-06-30';

    private const DICIEMBRE = '2026-12-31';

    private function servicio(): DescuentosLey
    {
        return app(DescuentosLey::class);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function junio(): array
    {
        return [
            'nada gravado' => [0, 0],
            'borde: fin del tramo I, exento' => [330000, 0],
            // 10 % de 0.01 = 0.00, más 106.20.
            'borde: inicio del tramo II' => [330001, 10620],
            // 10 % de 2,071.44 = 207.144 → 207.14, más 106.20.
            'borde: fin del tramo II' => [537144, 31334],
            // 20 % de 0.01 = 0.00, más 360.00.
            'borde: inicio del tramo III' => [537145, 36000],
            // 20 % de 6,857.16 = 1,371.432 → 1,371.43, más 360.00.
            'borde: fin del tramo III' => [1222860, 173143],
            // 30 % de 0.01 = 0.00, más 1,731.42.
            'borde: inicio del tramo IV' => [1222861, 173142],
            // 30 % de 7,771.40 = 2,331.42, más 1,731.42.
            'tramo del 30 %' => [2000000, 406284],
        ];
    }

    #[DataProvider('junio')]
    public function test_junio_aplica_la_tabla_semestral(int $gravado, int $renta): void
    {
        $calculo = $this->servicio()->recalcularRenta($gravado, 0, self::JUNIO);

        $this->assertSame($renta, $calculo['segun_tabla']);
        $this->assertSame($renta, $calculo['centavos']);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function diciembre(): array
    {
        return [
            // Con la deducción de 1,600: 8,200.00 − 1,600 = 6,600.00, exento.
            'deducción: queda justo en el exento' => [820000, 0],
            // 8,200.01 − 1,600 = 6,600.01: 10 % de 0.01 = 0.00, más 212.12.
            'deducción: entra al tramo II' => [820001, 21212],
            // 9,100.00 − 1,600 = 7,500.00: 10 % de 900.00 = 90.00, más 212.12.
            'deducción: último centavo con derecho' => [910000, 30212],
            // 9,100.01 ya no la lleva: 10 % de 2,500.01 = 250.001 → 250.00, más 212.12.
            'deducción: primer centavo sin derecho' => [910001, 46212],
            // 10 % de 4,142.86 = 414.286 → 414.29, más 212.12.
            'borde: fin del tramo II' => [1074286, 62641],
            // 20 % de 0.01 = 0.00, más 720.00.
            'borde: inicio del tramo III' => [1074287, 72000],
            // 20 % de 13,714.28 = 2,742.856 → 2,742.86, más 720.00.
            'borde: fin del tramo III' => [2445714, 346286],
            // 30 % de 0.01 = 0.00, más 3,462.86.
            'borde: inicio del tramo IV' => [2445715, 346286],
            // 30 % de 5,542.86 = 1,662.858 → 1,662.86, más 3,462.86.
            'tramo del 30 %' => [3000000, 512572],
        ];
    }

    #[DataProvider('diciembre')]
    public function test_diciembre_aplica_la_tabla_anual(int $gravado, int $renta): void
    {
        $calculo = $this->servicio()->recalcularRenta($gravado, 0, self::DICIEMBRE);

        $this->assertSame($renta, $calculo['segun_tabla']);
        $this->assertSame($renta, $calculo['centavos']);
    }

    public function test_diciembre_sin_deduccion_el_tramo_i_termina_en_6600(): void
    {
        $versiones = config('planilla_descuentos.versiones');
        unset($versiones[0]['recalculo'][12]['deduccion']);
        config(['planilla_descuentos.versiones' => $versiones]);

        $this->assertSame(0, $this->servicio()->recalcularRenta(660000, 0, self::DICIEMBRE)['centavos']);
        $this->assertSame(21212, $this->servicio()->recalcularRenta(660001, 0, self::DICIEMBRE)['centavos']);
        $this->assertSame([], $this->servicio()->recalcularRenta(660001, 0, self::DICIEMBRE)['avisos']);
    }

    public function test_la_misma_cifra_da_distinto_en_junio_que_en_diciembre(): void
    {
        // 6,000.00 en junio: 20 % de 628.56 = 125.712 → 125.71, más 360.00.
        $this->assertSame(48571, $this->servicio()->recalcularRenta(600000, 0, self::JUNIO)['centavos']);
        // En diciembre está en el exento: 6,000.00 − 1,600 = 4,400.00.
        $this->assertSame(0, $this->servicio()->recalcularRenta(600000, 0, self::DICIEMBRE)['centavos']);
    }

    public function test_resta_la_renta_ya_retenida(): void
    {
        $calculo = $this->servicio()->recalcularRenta(600000, 40000, self::JUNIO);

        $this->assertSame(48571, $calculo['segun_tabla']);
        $this->assertSame(8571, $calculo['centavos']);
    }

    public function test_si_la_diferencia_es_negativa_no_se_retiene_nada(): void
    {
        // «Si la diferencia es negativa no se retendrá valor alguno»: cero, no devolución.
        $this->assertSame(0, $this->servicio()->recalcularRenta(600000, 50000, self::JUNIO)['centavos']);
        $this->assertSame(0, $this->servicio()->recalcularRenta(600000, 48571, self::JUNIO)['centavos']);
    }

    public function test_un_semestre_con_el_mismo_sueldo(): void
    {
        // Base quincenal 500.00: ISSS 15.00, AFP 36.25, gravada 448.75, renta 30.23
        // (20 % de 1.13 = 0.226 → 0.23, más 30.00). Once quincenas antes de la última.
        $acumulado = $this->servicio()->proponerAcumulado(50000, 11, '2026-06-16');

        $this->assertSame(['gravado' => 493625, 'renta' => 33253], $acumulado);

        // 5,385.00 del semestre: 20 % de 13.56 = 2.712 → 2.71, más 360.00 = 362.71;
        // menos 332.53 retenidos.
        $calculo = $this->servicio()->recalcularRenta($acumulado['gravado'] + 44875, $acumulado['renta'], self::JUNIO);

        $this->assertSame(36271, $calculo['segun_tabla']);
        $this->assertSame(3018, $calculo['centavos']);
    }

    public function test_un_ano_con_el_mismo_sueldo(): void
    {
        $acumulado = $this->servicio()->proponerAcumulado(50000, 23, '2026-12-16');

        $this->assertSame(['gravado' => 1032125, 'renta' => 69529], $acumulado);

        // 10,770.00 del año, sin deducción: 20 % de 27.14 = 5.428 → 5.43, más 720.00 =
        // 725.43; menos 695.29 retenidos.
        $calculo = $this->servicio()->recalcularRenta($acumulado['gravado'] + 44875, $acumulado['renta'], self::DICIEMBRE);

        $this->assertSame(72543, $calculo['segun_tabla']);
        $this->assertSame(3014, $calculo['centavos']);
        $this->assertSame([], $calculo['avisos']);
    }

    public function test_avisa_la_deduccion_de_junio_y_la_aplicada_en_diciembre(): void
    {
        $this->assertStringContainsString('$1,600', $this->servicio()->recalcularRenta(400000, 0, self::JUNIO)['avisos'][0]);
        $this->assertSame([], $this->servicio()->recalcularRenta(600000, 0, self::JUNIO)['avisos']);

        $this->assertStringContainsString('Se restó', $this->servicio()->recalcularRenta(800000, 0, self::DICIEMBRE)['avisos'][0]);
        $this->assertSame([], $this->servicio()->recalcularRenta(0, 0, self::DICIEMBRE)['avisos']);
    }

    public function test_fuera_de_junio_y_diciembre_no_hay_recalculo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->servicio()->recalcularRenta(600000, 0, '2026-11-30');
    }

    public function test_sin_version_vigente_no_calcula(): void
    {
        $this->assertNull($this->servicio()->recalcularRenta(600000, 0, '2024-12-31'));
        $this->assertNull($this->servicio()->proponerAcumulado(50000, 3, '2024-12-31'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}> */
    public static function planillas(): array
    {
        return [
            'segunda quincena de junio' => ['quincenal', 'regular', '2026-06-16', '2026-06-30', true],
            'segunda quincena de diciembre' => ['quincenal', 'regular', '2026-12-16', '2026-12-31', true],
            'primera quincena de junio' => ['quincenal', 'regular', '2026-06-01', '2026-06-15', false],
            'primera quincena de diciembre' => ['quincenal', 'regular', '2026-12-01', '2026-12-15', false],
            'segunda quincena de noviembre' => ['quincenal', 'regular', '2026-11-16', '2026-11-30', false],
            'segunda quincena de enero' => ['quincenal', 'regular', '2027-01-16', '2027-01-31', false],
            'extraordinaria de fin de junio' => ['quincenal', 'extraordinaria', '2026-06-16', '2026-06-30', false],
            'mensual de diciembre' => ['mensual', 'regular', '2026-12-01', '2026-12-31', false],
        ];
    }

    #[DataProvider('planillas')]
    public function test_solo_el_ultimo_pago_de_junio_y_diciembre_lleva_recalculo(string $tipo, string $clase, string $desde, string $hasta, bool $lleva): void
    {
        $planilla = (new Planilla)->forceFill(['tipo_periodo' => $tipo, 'clase' => $clase, 'desde' => $desde, 'hasta' => $hasta]);

        $this->assertSame($lleva, $this->servicio()->esRecalculo($planilla));
    }
}
