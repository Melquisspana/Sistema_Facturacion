<?php

namespace Tests\Unit\Dte;

use App\DataTransferObjects\Dte\LineaDocumento;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\CalculoNoSoportadoException;
use App\Services\Dte\CalculadoraDte;
use Tests\TestCase;

/**
 * PASO 3A: CCF con líneas gravadas (IVA separado). Dinero exacto con BCMath.
 */
class CalculadoraDteTest extends TestCase
{
    private function calc(array $lineas, string|int|float $descuentoGlobal = 0): \App\DataTransferObjects\Dte\ResultadoCalculo
    {
        return (new CalculadoraDte())->calcular($lineas, TipoDte::CreditoFiscal, $descuentoGlobal);
    }

    public function test_ccf_gravado_simple(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(cantidad: 2, precio: 10),
        ]);

        $linea = $r->lineas[0];
        $this->assertSame('20.00', $linea->ventaGravada);
        $this->assertSame('0.00', $linea->ventaExenta);
        $this->assertSame('0.00', $linea->ventaNoSujeta);
        $this->assertSame('2.60', $linea->ivaLinea);
        $this->assertSame('22.60', $linea->totalLinea);

        $this->assertSame('20.00', $r->subtotal);
        $this->assertSame('20.00', $r->totalGravado);
        $this->assertSame('2.60', $r->ivaTotal);
        $this->assertSame('22.60', $r->totalPagar);
    }

    public function test_ccf_varias_lineas(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(2, 10),  // 20.00
            LineaDocumento::gravado(1, 5),   // 5.00
        ]);

        $this->assertSame('25.00', $r->totalGravado);
        $this->assertSame('3.25', $r->ivaTotal);    // 25 * 0.13
        $this->assertSame('28.25', $r->totalPagar);
        $this->assertCount(2, $r->lineas);
    }

    public function test_ccf_cantidad_decimal(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(2.5, 4),  // 10.00
        ]);

        $this->assertSame('10.00', $r->totalGravado);
        $this->assertSame('1.30', $r->ivaTotal);
        $this->assertSame('11.30', $r->totalPagar);
    }

    /**
     * Redondeo: el IVA del resumen se calcula sobre el TOTAL gravado, no como
     * suma de los IVA por línea. 3 líneas de 0.10: cada IVA de línea redondea a
     * 0.01 (suma 0.03), pero el IVA del documento es 0.30*0.13 = 0.039 → 0.04.
     */
    public function test_redondeo_iva_sobre_total(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(1, 0.10),
            LineaDocumento::gravado(1, 0.10),
            LineaDocumento::gravado(1, 0.10),
        ]);

        $this->assertSame('0.30', $r->totalGravado);
        $this->assertSame('0.04', $r->ivaTotal);   // 0.039 redondeado half-up
        $this->assertSame('0.34', $r->totalPagar);
        // Cada línea sí muestra su IVA redondeado individual.
        $this->assertSame('0.01', $r->lineas[0]->ivaLinea);
    }

    /** Suma exacta sin error de float: 0.10 + 0.20 = 0.30 (no 0.30000000004). */
    public function test_dinero_exacto_sin_float(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(1, 0.10),
            LineaDocumento::gravado(1, 0.20),
        ]);

        $this->assertSame('0.30', $r->totalGravado);
        $this->assertSame('0.04', $r->ivaTotal);
        $this->assertSame('0.34', $r->totalPagar);
    }

    // --- PASO 3B: exento / no sujeto / mezcla / descuentos ---

    public function test_ccf_linea_exenta(): void
    {
        $r = $this->calc([
            LineaDocumento::exento(2, 10),  // 20.00 exenta
        ]);

        $this->assertSame('20.00', $r->lineas[0]->ventaExenta);
        $this->assertSame('0.00', $r->lineas[0]->ivaLinea);
        $this->assertSame('20.00', $r->lineas[0]->totalLinea);

        $this->assertSame('0.00', $r->totalGravado);
        $this->assertSame('20.00', $r->totalExento);
        $this->assertSame('0.00', $r->totalNoSujeto);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('20.00', $r->subtotal);
        $this->assertSame('20.00', $r->totalPagar);
    }

    public function test_ccf_linea_no_sujeta(): void
    {
        $r = $this->calc([
            LineaDocumento::noSujeto(1, 15),  // 15.00 no sujeta
        ]);

        $this->assertSame('15.00', $r->lineas[0]->ventaNoSujeta);
        $this->assertSame('0.00', $r->lineas[0]->ivaLinea);
        $this->assertSame('15.00', $r->totalNoSujeto);
        $this->assertSame('0.00', $r->totalGravado);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('15.00', $r->totalPagar);
    }

    public function test_ccf_mezcla_gravado_exento_no_sujeto(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(2, 10),   // 20.00 gravada
            LineaDocumento::exento(1, 5),     // 5.00 exenta
            LineaDocumento::noSujeto(1, 3),   // 3.00 no sujeta
        ]);

        $this->assertSame('20.00', $r->totalGravado);
        $this->assertSame('5.00', $r->totalExento);
        $this->assertSame('3.00', $r->totalNoSujeto);
        $this->assertSame('28.00', $r->subtotal);
        $this->assertSame('2.60', $r->ivaTotal);     // solo 20.00 * 0.13
        $this->assertSame('30.60', $r->totalPagar);  // 28.00 + 2.60
    }

    public function test_ccf_mezcla_con_descuentos_por_linea(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(2, 10, descuento: 5),    // 20 - 5 = 15.00 gravada
            LineaDocumento::exento(1, 10, descuento: 2),     // 10 - 2 = 8.00 exenta
            LineaDocumento::noSujeto(1, 10, descuento: 1),   // 10 - 1 = 9.00 no sujeta
        ]);

        $this->assertSame('15.00', $r->totalGravado);
        $this->assertSame('8.00', $r->totalExento);
        $this->assertSame('9.00', $r->totalNoSujeto);
        $this->assertSame('32.00', $r->subtotal);
        $this->assertSame('1.95', $r->ivaTotal);     // 15.00 * 0.13
        $this->assertSame('33.95', $r->totalPagar);  // 32.00 + 1.95
        $this->assertSame('1.95', $r->lineas[0]->ivaLinea);
    }

    public function test_iva_solo_sobre_gravado(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(1, 10),     // 10.00 gravada
            LineaDocumento::exento(1, 1000),    // 1000.00 exenta (no genera IVA)
        ]);

        $this->assertSame('1.30', $r->ivaTotal);  // solo 10.00 * 0.13, no sobre 1010
        $this->assertSame('1010.00', $r->subtotal);
        $this->assertSame('1011.30', $r->totalPagar);
    }

    // --- PASO 3C: descuento global ---

    public function test_descuento_global_solo_gravado(): void
    {
        $r = $this->calc([LineaDocumento::gravado(2, 10)], 4);  // base 20, descuento global 4

        $this->assertSame('4.00', $r->descuentoGravado);
        $this->assertSame('0.00', $r->descuentoExento);
        $this->assertSame('0.00', $r->descuentoNoSujeto);
        $this->assertSame('4.00', $r->descuentoTotal);
        $this->assertSame('20.00', $r->totalGravado);
        $this->assertSame('20.00', $r->subtotal);
        $this->assertSame('2.08', $r->ivaTotal);     // (20 - 4) * 0.13
        $this->assertSame('18.08', $r->totalPagar);  // 20 - 4 + 2.08
    }

    public function test_descuento_global_solo_exento(): void
    {
        $r = $this->calc([LineaDocumento::exento(1, 20)], 5);

        $this->assertSame('0.00', $r->descuentoGravado);
        $this->assertSame('5.00', $r->descuentoExento);
        $this->assertSame('5.00', $r->descuentoTotal);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('15.00', $r->totalPagar);
    }

    public function test_descuento_global_solo_no_sujeto(): void
    {
        $r = $this->calc([LineaDocumento::noSujeto(1, 20)], 5);

        $this->assertSame('5.00', $r->descuentoNoSujeto);
        $this->assertSame('5.00', $r->descuentoTotal);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('15.00', $r->totalPagar);
    }

    public function test_descuento_global_mezcla_prorrateado(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(2, 10),   // 20
            LineaDocumento::exento(1, 5),     // 5
            LineaDocumento::noSujeto(1, 3),   // 3   subtotal 28
        ], 14);

        $this->assertSame('10.00', $r->descuentoGravado);   // 14 * 20/28
        $this->assertSame('2.50', $r->descuentoExento);     // 14 * 5/28
        $this->assertSame('1.50', $r->descuentoNoSujeto);   // 14 * 3/28
        $this->assertSame('14.00', $r->descuentoTotal);
        $this->assertSame('1.30', $r->ivaTotal);            // (20 - 10) * 0.13
        $this->assertSame('15.30', $r->totalPagar);         // 28 - 14 + 1.30

        // descuento_total = suma de los prorrateados
        $suma = number_format(
            (float) $r->descuentoGravado + (float) $r->descuentoExento + (float) $r->descuentoNoSujeto,
            2, '.', ''
        );
        $this->assertSame($r->descuentoTotal, $suma);
    }

    public function test_descuento_global_cero_no_altera_resultados(): void
    {
        $r = $this->calc([
            LineaDocumento::gravado(2, 10),
            LineaDocumento::exento(1, 5),
            LineaDocumento::noSujeto(1, 3),
        ], 0);

        $this->assertSame('0.00', $r->descuentoTotal);
        $this->assertSame('28.00', $r->subtotal);
        $this->assertSame('2.60', $r->ivaTotal);
        $this->assertSame('30.60', $r->totalPagar);
    }

    public function test_descuento_global_redondeo_no_exacto_suma_exacta(): void
    {
        // 10 + 10 + 10 = 30; descuento 10 → 3.3333 c/u → residuo 0.01 al bucket mayor.
        $r = $this->calc([
            LineaDocumento::gravado(1, 10),
            LineaDocumento::exento(1, 10),
            LineaDocumento::noSujeto(1, 10),
        ], 10);

        $this->assertSame('3.34', $r->descuentoGravado);   // 3.33 + residuo 0.01
        $this->assertSame('3.33', $r->descuentoExento);
        $this->assertSame('3.33', $r->descuentoNoSujeto);
        $this->assertSame('10.00', $r->descuentoTotal);    // suma EXACTA = descuento global
        $this->assertSame('0.87', $r->ivaTotal);           // (10 - 3.34) * 0.13 = 0.8658 → 0.87
        $this->assertSame('20.87', $r->totalPagar);        // 30 - 10 + 0.87
    }

    public function test_descuento_global_mayor_al_subtotal_falla(): void
    {
        $this->expectException(\App\Exceptions\Dte\DescuentoInvalidoException::class);
        $this->calc([LineaDocumento::gravado(2, 10)], 25);  // subtotal 20, descuento 25
    }

    public function test_tipo_aun_no_soportado_falla(): void
    {
        $this->expectException(CalculoNoSoportadoException::class);

        (new CalculadoraDte())->calcular(
            [LineaDocumento::gravado(1, 10)],
            TipoDte::NotaDebito,  // aún no soportado por la calculadora
        );
    }

    // --- PASO 3D: Factura consumidor final (IVA incluido) ---

    private function calcFactura(array $lineas, string|int|float $descuentoGlobal = 0): \App\DataTransferObjects\Dte\ResultadoCalculo
    {
        return (new CalculadoraDte())->calcular($lineas, TipoDte::Factura, $descuentoGlobal);
    }

    public function test_factura_gravado_simple_iva_incluido(): void
    {
        // Ejemplo: 1 × 1.13 (con IVA) → base 1.00, iva 0.13, total 1.13.
        $r = $this->calcFactura([LineaDocumento::gravado(1, 1.13)]);

        $this->assertSame('1.00', $r->lineas[0]->ventaGravada);
        $this->assertSame('0.13', $r->lineas[0]->ivaLinea);
        $this->assertSame('1.13', $r->lineas[0]->totalLinea);

        $this->assertSame('1.00', $r->totalGravado);   // base sin IVA
        $this->assertSame('0.13', $r->ivaTotal);
        $this->assertSame('1.13', $r->totalPagar);     // NO suma IVA dos veces
    }

    public function test_factura_no_suma_iva_dos_veces(): void
    {
        // 10 unidades a 11.30 con IVA = 113.00; base 100.00, iva 13.00.
        $r = $this->calcFactura([LineaDocumento::gravado(10, 11.30)]);

        $this->assertSame('100.00', $r->totalGravado);
        $this->assertSame('13.00', $r->ivaTotal);
        $this->assertSame('113.00', $r->totalPagar);   // = 100 + 13, no 113 + 13
    }

    public function test_factura_gravado_con_descuento_por_linea(): void
    {
        // 2 × 1.13 = 2.26 con IVA; descuento línea 1.13 → 1.13 → base 1.00, iva 0.13.
        $r = $this->calcFactura([LineaDocumento::gravado(2, 1.13, descuento: 1.13)]);

        $this->assertSame('1.00', $r->totalGravado);
        $this->assertSame('0.13', $r->ivaTotal);
        $this->assertSame('1.13', $r->totalPagar);
    }

    public function test_factura_gravado_con_descuento_global(): void
    {
        // 11.30 con IVA; descuento global 1.13 → neto 10.17 → base 9.00, iva 1.17.
        $r = $this->calcFactura([LineaDocumento::gravado(1, 11.30)], 1.13);

        $this->assertSame('1.13', $r->descuentoGravado);
        $this->assertSame('1.13', $r->descuentoTotal);
        $this->assertSame('9.00', $r->totalGravado);
        $this->assertSame('1.17', $r->ivaTotal);
        $this->assertSame('10.17', $r->totalPagar);    // 11.30 − 1.13
    }

    public function test_factura_mezcla_gravado_exento_no_sujeto(): void
    {
        $r = $this->calcFactura([
            LineaDocumento::gravado(1, 11.30),  // 11.30 con IVA → base 10.00, iva 1.30
            LineaDocumento::exento(1, 5),       // 5.00 exento
            LineaDocumento::noSujeto(1, 3),     // 3.00 no sujeto
        ]);

        $this->assertSame('10.00', $r->totalGravado);
        $this->assertSame('1.30', $r->ivaTotal);
        $this->assertSame('5.00', $r->totalExento);
        $this->assertSame('3.00', $r->totalNoSujeto);
        $this->assertSame('19.30', $r->totalPagar);    // 11.30 + 5 + 3, IVA dentro de los 11.30
    }

    public function test_factura_redondeo_precio_pequeno(): void
    {
        // 0.13 con IVA → base round(0.13/1.13,2)=0.12, iva 0.01, total 0.13.
        $r = $this->calcFactura([LineaDocumento::gravado(1, 0.13)]);

        $this->assertSame('0.12', $r->totalGravado);
        $this->assertSame('0.01', $r->ivaTotal);
        $this->assertSame('0.13', $r->totalPagar);
    }

    // --- PASO 3E: Factura de exportación tipo 11 (0% IVA + flete/seguro) ---

    private function calcFex(
        array $lineas,
        string|int|float $descuentoGlobal = 0,
        string|int|float $flete = 0,
        string|int|float $seguro = 0,
    ): \App\DataTransferObjects\Dte\ResultadoCalculo {
        return (new CalculadoraDte())->calcular(
            $lineas,
            TipoDte::FacturaExportacion,
            $descuentoGlobal,
            $flete,
            $seguro,
        );
    }

    public function test_fex_exportacion_simple_sin_iva(): void
    {
        // Ejemplo: 10 × 1.15 = 11.50 exportado; sin IVA. + flete 5 + seguro 2 = 18.50.
        $r = $this->calcFex([LineaDocumento::gravado(10, 1.15)], 0, 5, 2);

        $this->assertSame('11.50', $r->lineas[0]->ventaExportacion);
        $this->assertSame('0.00', $r->lineas[0]->ivaLinea);

        $this->assertSame('11.50', $r->subtotal);
        $this->assertSame('11.50', $r->totalExportacion);
        $this->assertSame('0.00', $r->totalGravado);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('5.00', $r->flete);
        $this->assertSame('2.00', $r->seguro);
        $this->assertSame('18.50', $r->totalPagar);   // 11.50 + 5 + 2
    }

    public function test_fex_sin_flete_ni_seguro(): void
    {
        $r = $this->calcFex([LineaDocumento::gravado(10, 1.15)]);

        $this->assertSame('11.50', $r->totalExportacion);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('0.00', $r->flete);
        $this->assertSame('0.00', $r->seguro);
        $this->assertSame('11.50', $r->totalPagar);
    }

    public function test_fex_con_descuento_por_linea(): void
    {
        // 10 × 1.15 = 11.50, descuento línea 1.50 → 10.00 exportado, sin IVA.
        $r = $this->calcFex([LineaDocumento::gravado(10, 1.15, descuento: 1.50)]);

        $this->assertSame('10.00', $r->totalExportacion);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('10.00', $r->totalPagar);
    }

    public function test_fex_con_descuento_global(): void
    {
        // 11.50 exportado, descuento global 1.50 → base 10.00, sin IVA.
        $r = $this->calcFex([LineaDocumento::gravado(10, 1.15)], 1.50);

        $this->assertSame('1.50', $r->descuentoExportacion);
        $this->assertSame('1.50', $r->descuentoTotal);
        $this->assertSame('0.00', $r->descuentoGravado);
        $this->assertSame('10.00', $r->totalExportacion);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('10.00', $r->totalPagar);
    }

    public function test_fex_con_flete(): void
    {
        $r = $this->calcFex([LineaDocumento::gravado(10, 1.15)], 0, 5);

        $this->assertSame('5.00', $r->flete);
        $this->assertSame('0.00', $r->seguro);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('16.50', $r->totalPagar);   // 11.50 + 5
    }

    public function test_fex_con_seguro(): void
    {
        $r = $this->calcFex([LineaDocumento::gravado(10, 1.15)], 0, 0, 2);

        $this->assertSame('0.00', $r->flete);
        $this->assertSame('2.00', $r->seguro);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('13.50', $r->totalPagar);   // 11.50 + 2
    }

    public function test_fex_con_flete_y_seguro_con_descuento_global(): void
    {
        // 11.50 − 1.50 = 10.00 + flete 5 + seguro 2 = 17.00, sin IVA.
        $r = $this->calcFex([LineaDocumento::gravado(10, 1.15)], 1.50, 5, 2);

        $this->assertSame('10.00', $r->totalExportacion);
        $this->assertSame('1.50', $r->descuentoTotal);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('17.00', $r->totalPagar);   // 10.00 + 5 + 2
    }

    public function test_fex_iva_siempre_cero(): void
    {
        // Varias líneas; el IVA total y por línea debe ser SIEMPRE 0.00.
        $r = $this->calcFex([
            LineaDocumento::gravado(3, 7.77),
            LineaDocumento::gravado(1, 100),
        ], 0, 10, 4);

        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('0.00', $r->totalGravado);
        foreach ($r->lineas as $linea) {
            $this->assertSame('0.00', $linea->ivaLinea);
            $this->assertSame('0.00', $linea->ventaGravada);
        }
    }

    public function test_fex_no_usa_logica_de_iva_incluido(): void
    {
        // Misma línea que la Factura (1 × 1.13). En Factura: base 1.00 + iva 0.13.
        // En exportación NO se separa IVA: la venta exportada es 1.13 completa.
        $r = $this->calcFex([LineaDocumento::gravado(1, 1.13)]);

        $this->assertSame('1.13', $r->totalExportacion);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('1.13', $r->totalPagar);   // no 1.00 + 0.13 separados
    }

    // --- PASO 3F: Retención de IVA (CCF a agente de retención) ---

    private function calcRet(array $lineas, string|int|float $descuentoGlobal = 0): \App\DataTransferObjects\Dte\ResultadoCalculo
    {
        return (new CalculadoraDte())->calcular(
            $lineas,
            TipoDte::CreditoFiscal,
            $descuentoGlobal,
            0,
            0,
            aplicaRetencion: true,
        );
    }

    public function test_ccf_gravado_con_retencion_1pct(): void
    {
        // gravado neto 100.00, IVA 13.00, retención 1.00 → total 112.00.
        $r = $this->calcRet([LineaDocumento::gravado(10, 10)]);

        $this->assertTrue($r->aplicaRetencion);
        $this->assertSame('1.00', $r->porcentajeRetencion);
        $this->assertSame('100.00', $r->totalGravado);
        $this->assertSame('100.00', $r->baseRetencion);
        $this->assertSame('13.00', $r->ivaTotal);
        $this->assertSame('1.00', $r->retencionIva);
        $this->assertSame('113.00', $r->totalAntesRetencion);
        $this->assertSame('112.00', $r->totalPagar);     // 113.00 − 1.00
    }

    public function test_ccf_gravado_sin_retencion(): void
    {
        // Mismo CCF pero sin solicitar retención: retención 0.00, total 113.00.
        $r = $this->calc([LineaDocumento::gravado(10, 10)]);

        $this->assertFalse($r->aplicaRetencion);
        $this->assertSame('0.00', $r->retencionIva);
        $this->assertSame('0.00', $r->baseRetencion);
        $this->assertSame('0.00', $r->porcentajeRetencion);
        $this->assertSame('113.00', $r->totalAntesRetencion);
        $this->assertSame('113.00', $r->totalPagar);
    }

    public function test_ccf_descuento_linea_retencion_sobre_gravado_neto(): void
    {
        // 10×10 = 100 − descuento línea 20 = 80 gravado neto.
        $r = $this->calcRet([LineaDocumento::gravado(10, 10, descuento: 20)]);

        $this->assertSame('80.00', $r->totalGravado);
        $this->assertSame('80.00', $r->baseRetencion);
        $this->assertSame('10.40', $r->ivaTotal);        // 80 × 0.13
        $this->assertSame('0.80', $r->retencionIva);     // 80 × 0.01
        $this->assertSame('90.40', $r->totalAntesRetencion);
        $this->assertSame('89.60', $r->totalPagar);      // 90.40 − 0.80
    }

    public function test_ccf_descuento_global_retencion_sobre_gravado_neto(): void
    {
        // 100 gravado − descuento global 20 → gravado neto 80.
        $r = $this->calcRet([LineaDocumento::gravado(10, 10)], 20);

        $this->assertSame('20.00', $r->descuentoGravado);
        $this->assertSame('80.00', $r->baseRetencion);   // gravado − descuento gravado
        $this->assertSame('10.40', $r->ivaTotal);
        $this->assertSame('0.80', $r->retencionIva);
        $this->assertSame('90.40', $r->totalAntesRetencion);
        $this->assertSame('89.60', $r->totalPagar);
    }

    public function test_ccf_mezcla_retencion_solo_sobre_gravado_neto(): void
    {
        $r = $this->calcRet([
            LineaDocumento::gravado(10, 10),  // 100 gravado
            LineaDocumento::exento(1, 50),    // 50 exento
            LineaDocumento::noSujeto(1, 30),  // 30 no sujeto
        ]);

        $this->assertSame('100.00', $r->baseRetencion);  // solo el gravado neto
        $this->assertSame('13.00', $r->ivaTotal);        // IVA solo sobre gravado
        $this->assertSame('1.00', $r->retencionIva);     // 100 × 0.01
        $this->assertSame('193.00', $r->totalAntesRetencion); // 180 + 13
        $this->assertSame('192.00', $r->totalPagar);     // 193 − 1
    }

    public function test_ccf_solo_exento_con_retencion_da_cero(): void
    {
        // Sin base gravada, aunque se solicite retención, retención = 0.00.
        $r = $this->calcRet([LineaDocumento::exento(1, 100)]);

        $this->assertTrue($r->aplicaRetencion);
        $this->assertSame('0.00', $r->baseRetencion);
        $this->assertSame('0.00', $r->retencionIva);
        $this->assertSame('100.00', $r->totalAntesRetencion);
        $this->assertSame('100.00', $r->totalPagar);
    }

    public function test_factura_con_aplica_retencion_no_aplica(): void
    {
        // Factura consumidor final: aunque se pida retención, NO se aplica.
        $r = (new CalculadoraDte())->calcular(
            [LineaDocumento::gravado(1, 1.13)],
            TipoDte::Factura,
            0,
            0,
            0,
            aplicaRetencion: true,
        );

        $this->assertFalse($r->aplicaRetencion);
        $this->assertSame('0.00', $r->retencionIva);
        $this->assertSame('1.13', $r->totalPagar);
    }

    public function test_fex_con_aplica_retencion_no_aplica(): void
    {
        // Factura de exportación: aunque se pida retención, NO se aplica.
        $r = (new CalculadoraDte())->calcular(
            [LineaDocumento::gravado(10, 1.15)],
            TipoDte::FacturaExportacion,
            0,
            5,
            2,
            aplicaRetencion: true,
        );

        $this->assertFalse($r->aplicaRetencion);
        $this->assertSame('0.00', $r->retencionIva);
        $this->assertSame('18.50', $r->totalPagar);   // 11.50 + 5 + 2, sin retención
    }

    public function test_ccf_retencion_redondeo(): void
    {
        // 77.77 gravado → retención 0.7777 → 0.78 (half-up).
        $r = $this->calcRet([LineaDocumento::gravado(1, 77.77)]);

        $this->assertSame('77.77', $r->baseRetencion);
        $this->assertSame('10.11', $r->ivaTotal);        // 77.77 × 0.13 = 10.1101 → 10.11
        $this->assertSame('0.78', $r->retencionIva);     // 0.7777 → 0.78
        $this->assertSame('87.88', $r->totalAntesRetencion); // 77.77 + 10.11
        $this->assertSame('87.10', $r->totalPagar);      // 87.88 − 0.78
    }

    public function test_documento_sin_lineas_falla(): void
    {
        $this->expectException(CalculoNoSoportadoException::class);
        $this->calc([]);
    }
}
