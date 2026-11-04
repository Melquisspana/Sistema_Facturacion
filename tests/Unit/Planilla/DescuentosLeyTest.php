<?php

namespace Tests\Unit\Planilla;

use App\Services\Planilla\DescuentosLey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Los valores esperados están escritos a mano, con la cuenta al lado: recalcularlos con
 * la misma fórmula no probaría nada.
 *
 * Los casos se eligen para caer exactos en el borde de cada tramo de renta, sobre la
 * remuneración gravada (base − ISSS − AFP), no sobre el sueldo.
 */
class DescuentosLeyTest extends TestCase
{
    private const FECHA = '2026-11-01';

    private function importes(int $base, string $fecha = self::FECHA): array
    {
        $calculo = app(DescuentosLey::class)->calcular($base, $fecha);

        return array_map(fn (array $c) => $c['centavos'], $calculo['conceptos']);
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int}> */
    public static function casos(): array
    {
        return [
            // 3 % = 6.132; 7.25 % = 14.819. Gravada 183.45: no llega a renta.
            'salario mínimo, media quincena de 408.80' => [20440, 613, 1482, 0],

            // Gravada 306.40 − 9.19 − 22.21 = 275.00: último centavo del Tramo I.
            'borde: fin del tramo I' => [30640, 919, 2221, 0],
            // Gravada 306.41 − 9.19 − 22.21 = 275.01: 10 % de 0.01 = 0.00, más 8.83.
            'borde: inicio del tramo II' => [30641, 919, 2221, 883],

            // Gravada 498.74 − 14.96 − 36.16 = 447.62: 10 % de 172.62 = 17.26, más 8.83.
            'borde: fin del tramo II' => [49874, 1496, 3616, 2609],
            // Gravada 498.75 − 14.96 − 36.16 = 447.63: 20 % de 0.01 = 0.00, más 30.00.
            'borde: inicio del tramo III' => [49875, 1496, 3616, 3000],

            // Gravada 1114.88 − 15.00 − 80.83 = 1019.05: 20 % de 571.43 = 114.29, más 30.00.
            'borde: fin del tramo III' => [111488, 1500, 8083, 14429],
            // Gravada 1114.89 − 15.00 − 80.83 = 1019.06: 30 % de 0.01 = 0.00, más 144.28.
            'borde: inicio del tramo IV' => [111489, 1500, 8083, 14428],

            // Gravada 1000 − 15 − 72.50 = 912.50: 20 % de 464.88 = 92.98, más 30.00.
            'sueldo medio' => [100000, 1500, 7250, 12298],
            // Gravada 3000 − 15 − 217.50 = 2767.50: 30 % de 1748.45 = 524.535 → 524.54,
            // más 144.28. Además cae exacto en medio centavo.
            'sueldo alto' => [300000, 1500, 21750, 66882],
        ];
    }

    #[DataProvider('casos')]
    public function test_calcula_isss_afp_y_renta(int $base, int $isss, int $afp, int $renta): void
    {
        $this->assertSame(['isss' => $isss, 'afp' => $afp, 'renta' => $renta], $this->importes($base));
    }

    public function test_el_tope_del_isss_es_el_mensual_repartido_entre_los_pagos(): void
    {
        $this->assertSame(1500, $this->importes(50000)['isss']);
        $this->assertSame(1500, $this->importes(50001)['isss']);
        $this->assertSame(1500, $this->importes(200000)['isss']);
    }

    public function test_la_afp_no_tiene_tope_salvo_que_se_configure(): void
    {
        $this->assertSame(14500, $this->importes(200000)['afp']);

        $versiones = config('planilla_descuentos.versiones');
        $versiones[0]['afp']['tope_mensual'] = '2000.00';
        config(['planilla_descuentos.versiones' => $versiones]);

        // Tope de 1000.00 por quincena: 7.25 % = 72.50.
        $this->assertSame(7250, $this->importes(200000)['afp']);
    }

    public function test_el_medio_centavo_se_redondea_hacia_arriba(): void
    {
        // 1.50 × 3 % = 0.045 → 0.05; 2.00 × 7.25 % = 0.145 → 0.15.
        $this->assertSame(5, $this->importes(150)['isss']);
        $this->assertSame(15, $this->importes(200)['afp']);
    }

    public function test_base_cero_o_negativa_da_cero(): void
    {
        $this->assertSame(['isss' => 0, 'afp' => 0, 'renta' => 0], $this->importes(0));
        $this->assertSame(['isss' => 0, 'afp' => 0, 'renta' => 0], $this->importes(-500));
    }

    public function test_rige_la_version_vigente_en_la_fecha(): void
    {
        $versiones = config('planilla_descuentos.versiones');
        $nueva = $versiones[0];
        $nueva['vigente_desde'] = '2027-01-01';
        $nueva['isss']['tasa'] = '4.00';
        config(['planilla_descuentos.versiones' => [$nueva, $versiones[0]]]);

        $this->assertSame(600, $this->importes(20000, '2026-12-31')['isss']);
        $this->assertSame(800, $this->importes(20000, '2027-01-01')['isss']);
    }

    public function test_sin_version_vigente_no_calcula(): void
    {
        $this->assertNull(app(DescuentosLey::class)->calcular(20440, '2025-04-30'));
        $this->assertFalse(app(DescuentosLey::class)->hayVigentes('2025-04-30'));
        $this->assertTrue(app(DescuentosLey::class)->hayVigentes('2025-05-01'));
    }

    public function test_avisa_la_deduccion_del_tramo_ii(): void
    {
        $servicio = app(DescuentosLey::class);

        $this->assertSame([], $servicio->calcular(20440, self::FECHA)['avisos']);
        $this->assertStringContainsString('$1,600', $servicio->calcular(40000, self::FECHA)['avisos'][0]);

        // El recálculo de junio y diciembre ya no se avisa: se calcula al generar.
        $this->assertSame([], $servicio->calcular(100000, '2026-12-16')['avisos']);
    }

    public function test_la_base_deja_afuera_lo_que_no_cotiza(): void
    {
        $base = app(DescuentosLey::class)->baseDeLinea(20000, [
            ['tipo' => 'ingreso', 'concepto' => 'Comisión', 'importe' => '50.00'],
            ['tipo' => 'ingreso', 'concepto' => 'VIATICOS', 'importe' => '30.00'],
            ['tipo' => 'ingreso', 'concepto' => 'Bono', 'importe' => '10.00'],
            ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '20.00'],
        ]);

        $this->assertSame(25000, $base['base']);
        $this->assertStringContainsString('extraordinarios', $base['avisos'][0]);
    }
}
