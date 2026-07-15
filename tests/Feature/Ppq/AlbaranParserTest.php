<?php

namespace Tests\Feature\Ppq;

use App\Services\Ppq\AlbaranParser;
use Tests\TestCase;

class AlbaranParserTest extends TestCase
{
    private function parser(): AlbaranParser
    {
        return new AlbaranParser();
    }

    public function test_extrae_monto_oc_y_numero_de_texto(): void
    {
        $texto = <<<TXT
        CALLEJA, S.A. DE C.V.
        ALBARAN  AC01/0039/00/6703
        Orden de compra: 260600232002345
        Producto X   10   2.50    25.00
        Producto Y    5   3.00    15.00
        TOTAL  \$ 1,234.56
        TXT;

        $r = $this->parser()->desdeTexto($texto);

        $this->assertSame(1234.56, $r['monto']);                       // monto total normalizado
        $this->assertSame('260600232002345', $r['oc']);                // OC
        $this->assertStringContainsString('AC01/0039', (string) $r['numero']);
        $this->assertNotEmpty($r['debug']['candidatos_monto']);        // debug con candidatos
    }

    public function test_monto_formato_europeo(): void
    {
        // 1.234,56 (punto miles, coma decimal)
        $r = $this->parser()->desdeTexto('Total a pagar: 1.234,56');
        $this->assertSame(1234.56, $r['monto']);
    }

    public function test_sin_monto_devuelve_null_y_preview(): void
    {
        $r = $this->parser()->desdeTexto('Documento sin importes legibles');
        $this->assertNull($r['monto']);
        $this->assertArrayHasKey('texto_preview', $r['debug']);
    }

    public function test_total_albaran_no_se_confunde_con_base_iva_re(): void
    {
        // Caso real reportado (albarán de La Unión): el cuadro de totales trae varias
        // líneas "Total"-like (Base/IVA/R.E./Total) ANTES de la etiqueta inequívoca
        // "TOTAL ALBARAN". El monto correcto es 138.87, no 123.99 (Base), 16.12 (IVA
        // o el "Total" del desglose) ni -1.24 (R.E.).
        $texto = <<<TXT
        CALLEJA, S.A. DE C.V.
        ALBARAN  AC01/232/00/2878
        Orden de compra: 260600232002345
        Base 123.99
        IVA 16.12
        R.E. -1.24
        Total 16.12 / -1.24
        TOTAL ALBARAN 138.87 USD
        TXT;

        $r = $this->parser()->desdeTexto($texto);

        $this->assertSame(138.87, $r['monto']);
        $this->assertNotNull($r['monto'], 'PPQ no debe mostrar "Albarán sin monto" cuando SÍ hay TOTAL ALBARAN.');
    }

    public function test_total_albaran_variantes_de_formato(): void
    {
        $casos = [
            'TOTAL ALBARAN 138.87 USD' => 138.87,
            'TOTAL ALBARÁN 138.87 USD' => 138.87,   // con acento
            "TOTAL ALBARAN:\n138.87" => 138.87,       // dos puntos + salto de línea
            'TOTAL ALBARAN     138.87 UD' => 138.87,  // espacios múltiples + "UD"
            'TOTAL ALBARAN     138.87 USD' => 138.87, // espacios múltiples + "USD"
        ];

        foreach ($casos as $texto => $esperado) {
            $r = $this->parser()->desdeTexto($texto);
            $this->assertSame($esperado, $r['monto'], "Falló con: {$texto}");
        }
    }
}
