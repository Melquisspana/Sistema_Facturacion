<?php

namespace Tests\Feature\Ordenes;

use App\Services\Ppq\OrdenCompraParser;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

/**
 * Lectura de la orden de compra del EDI. Textos ficticios con la misma forma que el
 * PDF real: proveedor, cliente, sala y productos inventados.
 */
class OrdenCompraParserTest extends TestCase
{
    /** Texto de una orden con la forma del EDI. */
    public static function texto(string $numero, string $sala = '0232', string $creacion = '05-11-2026', string $despacho = '07-11-2026'): string
    {
        return <<<TXT
        06/11/2026            Orden de Compra            # 4
        PROVEEDOR DE PRUEBA
        Vendor Number: 009999
        Orden de Compra # {$numero} EDI
        Cliente: CLIENTE DE PRUEBA S.A. DE C.V. - 0000000000001      Creación: {$creacion}
        Entrega en: {$sala} - SALA DE PRUEBA NORTE           Despacho: {$despacho}
        Tipo Orden:
        Comentario:
        Código Interno | Código | Artículo | Cantidad Pedidas | Unidades Pedidas | Precio Unitario | Descuento | Impuesto | Total
        0 | 7400000000017 | DULCE DE PRUEBA :: 1 | 10 | 10 | 1.0400 | 0.0000 | 1.3520 | 10.4000
        0 | 7400000000024 | CARAMELO DE PRUEBA 12 UN | 5 | 5 | 2.5000 | 0.0000 | 1.6250 | 12.5000
        TXT;
    }

    /** Un PDF ficticio armado con dompdf con ese texto. */
    public static function pdf(string $texto): string
    {
        return Pdf::loadHTML('<pre style="font-size:9px">'.e($texto).'</pre>')->output();
    }

    public function test_lee_numero_de_15_digitos_sala_fechas_lineas_y_total(): void
    {
        $datos = (new OrdenCompraParser)->desdeTexto(self::texto('261102320010394'));

        $this->assertSame('261102320010394', $datos['numero']);
        $this->assertSame('0232', $datos['sala_codigo']);
        $this->assertSame('2026-11-05', $datos['fecha_creacion']);
        $this->assertSame('2026-11-07', $datos['fecha_despacho']);
        $this->assertSame('leida', $datos['estado']);
        $this->assertCount(2, $datos['lineas']);
        $this->assertSame(['codigo' => '7400000000017', 'articulo' => 'DULCE DE PRUEBA :: 1', 'cantidad' => '10', 'precio' => '1.0400', 'total' => '10.4000'], $datos['lineas'][0]);
        $this->assertSame('CARAMELO DE PRUEBA 12 UN', $datos['lineas'][1]['articulo']);
        // Sin «Total:» impreso, la suma de las líneas.
        $this->assertSame('22.90', $datos['total']);
    }

    public function test_lee_numeros_de_13_y_14_digitos(): void
    {
        $parser = new OrdenCompraParser;

        $this->assertSame('2611023200103', $parser->desdeTexto(self::texto('2611023200103'))['numero']);
        $this->assertSame('26110232001039', $parser->desdeTexto(self::texto('26110232001039'))['numero']);
    }

    public function test_el_total_impreso_manda_sobre_la_suma(): void
    {
        $datos = (new OrdenCompraParser)->desdeTexto(self::texto('261102320010394')."\nTotal: 1,025.88\n");

        $this->assertSame('1025.88', $datos['total']);
    }

    public function test_tolera_otro_orden_y_otros_espacios(): void
    {
        // Como lo puede sacar smalot: la etiqueta separada del número y las columnas con tabuladores.
        $texto = "Entrega en:0045 -  SALA DE PRUEBA SUR\tDespacho:\t08/11/2026\nOrden de Compra\nCreación:\t06/11/2026\n"
            ."EDI 2611004500123\n0\t7400000000017\tDULCE DE PRUEBA\t3\t3\t1.0400\t0.0000\t0.4056\t3.1200\n";

        $datos = (new OrdenCompraParser)->desdeTexto($texto);

        $this->assertSame('2611004500123', $datos['numero']);
        $this->assertSame('0045', $datos['sala_codigo']);
        $this->assertSame('2026-11-06', $datos['fecha_creacion']);
        $this->assertSame('2026-11-08', $datos['fecha_despacho']);
        $this->assertSame('3.12', $datos['total']);
    }

    public function test_sin_entrega_en_la_sala_sale_del_numero(): void
    {
        $texto = str_replace('Entrega en: 0232 - SALA DE PRUEBA NORTE', 'SALA DE PRUEBA NORTE', self::texto('261102320010394'));

        $this->assertSame('0232', (new OrdenCompraParser)->desdeTexto($texto)['sala_codigo']);
    }

    public function test_no_confunde_codigos_de_barras_con_el_numero(): void
    {
        // Sin la etiqueta junto al número, los códigos de 13 dígitos de productos y cliente no cuentan.
        $texto = str_replace('Orden de Compra # 261102320010394 EDI', 'Orden de Compra', self::texto('261102320010394'));

        $datos = (new OrdenCompraParser)->desdeTexto($texto);

        $this->assertNull($datos['numero']);
        $this->assertSame('sin_leer', $datos['estado']);
    }

    public function test_texto_ilegible_queda_sin_leer(): void
    {
        $datos = (new OrdenCompraParser)->desdeTexto("%%%\nnada que leer\n");

        $this->assertNull($datos['numero']);
        $this->assertNull($datos['sala_codigo']);
        $this->assertNull($datos['lineas']);
        $this->assertNull($datos['total']);
        $this->assertSame('sin_leer', $datos['estado']);
        $this->assertSame('sin_leer', (new OrdenCompraParser)->desdePdf('no es un pdf')['estado']);
    }

    public function test_lee_un_pdf_armado_con_dompdf(): void
    {
        $datos = (new OrdenCompraParser)->desdePdf(self::pdf(self::texto('26110232001039')));

        $this->assertSame('26110232001039', $datos['numero']);
        $this->assertSame('0232', $datos['sala_codigo']);
        $this->assertSame('2026-11-05', $datos['fecha_creacion']);
        $this->assertSame('leida', $datos['estado']);
    }
}
