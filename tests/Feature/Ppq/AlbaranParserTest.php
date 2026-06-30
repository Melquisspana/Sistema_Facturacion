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
}
