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

    /** Diez líneas ficticias: [código de barras, artículo, costo unitario, cantidad]. */
    public const LINEAS_EDI = [
        ['7411350000011', 'DULCE DE PRUEBA UNO 8 ONZ', '1.2500', '12.000'],
        ['7411350000028', 'CARAMELO DE PRUEBA DOS', '0.8700', '6.000'],
        ['7411350000035', 'TURRON DE PRUEBA', '1.1000', '10.000'],
        ['7411350000042', 'COCADA DE PRUEBA', '0.9900', '7.000'],
        ['7411350000059', 'MANI DE PRUEBA 7 UNID', '1.0350', '3.000'],
        ['7411350000066', 'JALEA DE PRUEBA', '1.5000', '4.000'],
        // Un código con un «0» donde iría la sala: tampoco pasa por número de orden.
        ['7410020300015', 'MELCOCHA DE PRUEBA', '0.6500', '20.000'],
        ['7411350000080', 'ALFAJOR DE PRUEBA', '1.3300', '5.000'],
        ['7411350000097', 'CONSERVA DE PRUEBA', '0.7700', '9.000'],
        ['7411350000103', 'BOMBON DE PRUEBA', '2.0500', '2.000'],
    ];

    /**
     * Texto como lo saca smalot del PDF real del EDI: etiquetas del encabezado juntas, la
     * sala después de «Número:», la tabla POR COLUMNAS (descripciones, costos, códigos,
     * cantidades y otros bloques) y el número completo solo en su línea al pie. Todo
     * inventado: empresa, sala, productos, operador y números.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $lineas
     */
    public static function textoEdi(
        string $numero = '26110317004521',
        string $sala = "0317 SUPERMERCADO DE PRUEBA LAS \nCOLINAS",
        array $lineas = self::LINEAS_EDI,
        string $operador = 'OPER9901 OPERADOR DE PRUEBA',
    ): string {
        $columna = fn (int $i, string $antes = '') => implode("\n", array_map(fn (array $l) => $antes.$l[$i], $lineas));
        $repetir = fn (string $fila) => implode("\n", array_fill(0, count($lineas), $fila));

        return implode("\n", [
            'EMPRESA COMPRADORA DE PRUEBA, S.A. DE C.V.', 'Cantidad', 'Solicitada',
            'PAGINA:', 'FECHA:', 'HORA:', '1/', '04/11/2026', '10:15:30',
            'Proveedor:', 'Sucursal:', 'Número:',
            $sala,
            '00 / 26 / 4521',
            'P9 009999 _ PROVEEDOR DE PRUEBA',
            'Despacho:06/11/2026',
            "Código\tDescripción", 'Costo ', 'Unitario', 'Costo', 'Real',
            $columna(1), $columna(2, ' '), $columna(0), $columna(3, ' '),
            $repetir(' 0.000'), $repetir('0.00 . 0.00 . 0.00'),
            'Bonif. Descs.', 'Orden de Compra', 'Reg. Fisc.:', 'Descuento:', 'General: ', 'Cond. Pago: ',
            '000000-0', 'CREDITO A 30 DIAS', 'Factor', 'de Caja.',
            $columna(2, ' '), $repetir(' 1.000'),
            'Nota:', "Digitado por\tAutorizado por\tProveedor", ' 3.90', '5.00% . 0.00 . 0.00', 'Aprobado',
            $repetir('UN'),
            'Fecha Entrega:04/12/2026 Cancelación:05/12/2026',
            'USUARIO:', $operador,
            $numero,
            '1',
        ])."\n";
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

    public function test_lee_el_formato_real_del_edi_por_columnas(): void
    {
        $datos = (new OrdenCompraParser)->desdeTexto(self::textoEdi());

        $this->assertSame('26110317004521', $datos['numero']);
        $this->assertSame('0317', $datos['sala_codigo']);
        // La fecha de emisión del encabezado, no la de entrega ni la de cancelación.
        $this->assertSame('2026-11-04', $datos['fecha_creacion']);
        $this->assertSame('2026-11-06', $datos['fecha_despacho']);
        $this->assertSame('leida', $datos['estado']);
        $this->assertCount(10, $datos['lineas']);
        $this->assertSame(['codigo' => '7411350000011', 'articulo' => 'DULCE DE PRUEBA UNO 8 ONZ', 'cantidad' => '12.000', 'precio' => '1.2500', 'total' => '15.00'], $datos['lineas'][0]);
        // 3 × 1.035 = 3.105: se redondea hacia arriba.
        $this->assertSame('3.11', $datos['lineas'][4]['total']);
        $this->assertSame(['7410020300015', 'MELCOCHA DE PRUEBA', '20.000', '0.6500', '13.00'], array_values($datos['lineas'][6]));
        $this->assertSame('4.10', $datos['lineas'][9]['total']);
        // Sin total impreso: la suma de las líneas, antes del descuento general del 5 %.
        $this->assertSame('77.94', $datos['total']);
    }

    public function test_formato_del_edi_con_la_sala_en_una_linea_y_el_operador_con_tabulador(): void
    {
        $datos = (new OrdenCompraParser)->desdeTexto(self::textoEdi(
            numero: '2611004500777',
            sala: "\t0045 SALA DE PRUEBA SUR",
            lineas: array_slice(self::LINEAS_EDI, 0, 2),
            operador: "OPER0451\tOPERADOR DE PRUEBA SUR",
        ));

        $this->assertSame('2611004500777', $datos['numero']);
        $this->assertSame('0045', $datos['sala_codigo']);
        $this->assertCount(2, $datos['lineas']);
        $this->assertSame('CARAMELO DE PRUEBA DOS', $datos['lineas'][1]['articulo']);
        $this->assertSame('5.22', $datos['lineas'][1]['total']);
        $this->assertSame('20.22', $datos['total']);
    }

    public function test_un_codigo_de_barras_nunca_es_el_numero_de_orden(): void
    {
        $parser = new OrdenCompraParser;

        // Sin la línea del número al pie: los códigos de 13 dígitos sueltos no cuentan.
        $sinNumero = $parser->desdeTexto(self::textoEdi(numero: ''));
        $this->assertNull($sinNumero['numero']);
        $this->assertSame('sin_leer', $sinNumero['estado']);
        $this->assertSame('0317', $sinNumero['sala_codigo']);
        $this->assertCount(10, $sinNumero['lineas']);

        // Tampoco sin la sala del encabezado, aunque un código tenga «0» donde iría la sala.
        $sinSala = $parser->desdeTexto(self::textoEdi(numero: '', sala: 'SALA SIN CODIGO'));
        $this->assertNull($sinSala['numero']);
        $this->assertNull($sinSala['sala_codigo']);

        // Un número al pie de otra sala que la del encabezado tampoco.
        $this->assertNull($parser->desdeTexto(self::textoEdi(numero: '26110999004521'))['numero']);
    }

    public function test_si_los_bloques_no_cuadran_no_inventa_lineas(): void
    {
        // Un costo de menos: nueve costos para diez códigos.
        $texto = preg_replace('/^ 2\.0500\n/m', '', self::textoEdi(), 1);
        $datos = (new OrdenCompraParser)->desdeTexto($texto);

        $this->assertNull($datos['lineas']);
        $this->assertNull($datos['total']);
        $this->assertSame('26110317004521', $datos['numero']);
        $this->assertSame('0317', $datos['sala_codigo']);
        $this->assertSame('2026-11-04', $datos['fecha_creacion']);
        $this->assertSame('2026-11-06', $datos['fecha_despacho']);
        $this->assertSame('leida', $datos['estado']);

        // Una descripción partida en dos líneas: once descripciones para diez códigos.
        $partida = str_replace("BOMBON DE PRUEBA\n", "BOMBON DE\nPRUEBA\n", self::textoEdi());
        $this->assertNull((new OrdenCompraParser)->desdeTexto($partida)['lineas']);
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
