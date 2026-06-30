<?php

namespace Tests\Feature\Ppq;

use App\Services\Ppq\DteCorreoParser;
use Tests\TestCase;

class DteCorreoParserTest extends TestCase
{
    private function parser(): DteCorreoParser
    {
        return new DteCorreoParser();
    }

    public function test_extrae_dte_pelado_con_oc_en_apendice(): void
    {
        $json = [
            'identificacion' => [
                'numeroControl' => 'DTE-03-M001P001-000000000000986',
                'codigoGeneracion' => 'ABC-123',
                'tipoDte' => '03',
                'fecEmi' => '2026-06-20',
            ],
            'resumen' => ['totalPagar' => 146.56, 'montoTotalOperacion' => 147.87],
            'receptor' => ['nombre' => 'Calleja SA de CV', 'nombreComercial' => 'Súper Selectos Ilobasco'],
            'apendice' => [['campo' => 'ordenCompra', 'etiqueta' => 'Orden de compra', 'valor' => '2606026002401']],
            'selloRecibido' => 'SELLO-XYZ',
        ];

        $r = $this->parser()->desdeJson($json);

        $this->assertSame('DTE-03-M001P001-000000000000986', $r['numeroControl']);
        $this->assertSame('ABC-123', $r['codigoGeneracion']);
        $this->assertSame('SELLO-XYZ', $r['sello']);
        $this->assertSame('03', $r['tipoDte']);
        $this->assertSame('2606026002401', $r['ordenCompra']);
        $this->assertSame('0260', $r['sala']);          // sala = 4 dígitos tras el YYMM
        $this->assertSame('Súper Selectos Ilobasco', $r['salaNombre']); // nombre de sala = nombre comercial del receptor
        $this->assertSame(146.56, $r['monto']);
        $this->assertSame('2026-06-20', $r['fecha']);
    }

    public function test_extrae_dte_envuelto_estilo_contaportable(): void
    {
        $json = [
            'documento' => [
                'identificacion' => ['numeroControl' => 'DTE-05-X', 'codigoGeneracion' => 'GEN-9', 'tipoDte' => '05', 'fecEmi' => '2026-06-19'],
                'resumen' => ['montoTotalOperacion' => 50.0],
                'numeroOrdenCompra' => '260600999001111',
            ],
            'respuestaMH' => ['selloRecibido' => 'SELLO-MH-1'],
        ];

        $r = $this->parser()->desdeJson($json);

        $this->assertSame('DTE-05-X', $r['numeroControl']);
        $this->assertSame('SELLO-MH-1', $r['sello']);
        $this->assertSame('05', $r['tipoDte']);
        $this->assertSame('260600999001111', $r['ordenCompra']);
        $this->assertSame(50.0, $r['monto']);
    }

    public function test_campos_faltantes_quedan_null(): void
    {
        $r = $this->parser()->desdeJson(['identificacion' => []]);

        $this->assertNull($r['numeroControl']);
        $this->assertNull($r['sello']);
        $this->assertNull($r['ordenCompra']);
        $this->assertNull($r['salaNombre']);
        $this->assertNull($r['monto']);
    }
}
