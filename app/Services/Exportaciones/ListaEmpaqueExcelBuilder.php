<?php

namespace App\Services\Exportaciones;

use App\Models\Exportacion;
use App\Models\ExportacionItem;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Construye la hoja «Lista» del Excel de lista de empaque DESDE CERO, sin
 * depender de ningún archivo de plantilla.
 *
 * POR QUÉ. El generador anterior cargaba
 * `storage/app/templates/exportaciones/lista_empaque.xlsx` y rellenaba sus
 * celdas. Ese archivo no está en el disco, así que la única salida del módulo
 * lanzaba una excepción — y como nadie había creado nunca una lista, el fallo
 * llevaba meses invisible. Un formato que depende de un binario que no está
 * versionado no es reproducible: se va con un despliegue limpio y no hay prueba
 * que lo detecte.
 *
 * Se conserva EXACTAMENTE el mismo layout que rellenaba el generador anterior
 * —mismas columnas B..T, productos desde la fila 9, mismas fórmulas por fila y la
 * fila de totales con `=SUM(...)`—, para que el archivo que recibe el importador
 * siga siendo el de siempre. Lo único que cambia es de dónde sale la cuadrícula.
 *
 * Las FÓRMULAS se conservan a propósito, en vez de escribir el número calculado:
 * el archivo se abre en Excel y se edita a mano (cambiar cajas y ver el total
 * recalcularse es parte del uso real). Un archivo de solo valores rompería eso.
 */
class ListaEmpaqueExcelBuilder
{
    /** Primera fila de productos. Igual que en el formato histórico. */
    public const FILA_PRIMER_PRODUCTO = 9;

    /** Constantes de conversión, visibles en la hoja como en el formato original. */
    private const FACTOR_GRAMOS_A_ONZAS = 0.035274;

    private const FACTOR_KG_A_LB = 2.2046;

    private const ANCHOS = [
        'A' => 2.5, 'B' => 8, 'C' => 46, 'D' => 20, 'E' => 9, 'F' => 9, 'G' => 9,
        'H' => 9, 'I' => 11, 'J' => 11, 'K' => 13, 'L' => 9, 'M' => 9, 'N' => 11,
        'O' => 11, 'P' => 9, 'Q' => 9, 'R' => 9, 'S' => 11, 'T' => 11,
    ];

    /** Cabeceras: columna ⇒ [español, inglés]. El orden fija el layout. */
    private const CABECERAS = [
        'B' => ['CAJAS', 'BOXES'],
        'C' => ['DESCRIPCIÓN', 'DESCRIPTION'],
        'D' => ['EMPAQUE', 'PACKING'],
        'E' => ['UNID./CAJA', 'UNITS/BOX'],
        'F' => ['GRAMOS', 'GRAMS'],
        'G' => ['FACTOR', 'FACTOR'],
        'H' => ['ONZAS', 'OUNCES'],
        'I' => ['TOTAL UNID.', 'TOTAL UNITS'],
        'J' => ['PRECIO CAJA', 'BOX PRICE'],
        'K' => ['VALOR TOTAL', 'TOTAL VALUE'],
        'L' => ['NETO KG', 'NET KG'],
        'M' => ['BRUTO KG', 'GROSS KG'],
        'N' => ['TOTAL NETO KG', 'TOTAL NET KG'],
        'O' => ['TOTAL BRUTO KG', 'TOTAL GROSS KG'],
        'P' => ['FACTOR', 'FACTOR'],
        'Q' => ['NETO LB', 'NET LB'],
        'R' => ['BRUTO LB', 'GROSS LB'],
        'S' => ['TOTAL NETO LB', 'TOTAL NET LB'],
        'T' => ['TOTAL BRUTO LB', 'TOTAL GROSS LB'],
    ];

    /** Columnas que llevan una suma al pie. */
    private const COLUMNAS_TOTALIZADAS = ['B', 'I', 'K', 'N', 'O', 'S', 'T'];

    public function construir(Exportacion $exportacion): Spreadsheet
    {
        $exportacion->loadMissing('items');

        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Lista');

        $this->prepararHoja($hoja);
        $this->escribirEncabezado($hoja, $exportacion);
        $this->escribirCabeceras($hoja);
        $ultimaFilaProducto = $this->escribirProductos($hoja, $exportacion);
        $this->escribirTotales($hoja, $ultimaFilaProducto);

        $hoja->setSelectedCell('A1');

        return $spreadsheet;
    }

    private function prepararHoja(Worksheet $hoja): void
    {
        foreach (self::ANCHOS as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        $hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $hoja->getPageSetup()->setFitToWidth(1);
        $hoja->getPageSetup()->setFitToHeight(0);
        // La cabecera se repite al imprimir: una lista de 40 productos ocupa varias
        // páginas y sin esto las columnas de la segunda hoja en adelante no se leen.
        $hoja->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(7, 8);
    }

    private function escribirEncabezado(Worksheet $hoja, Exportacion $e): void
    {
        $hoja->mergeCells('B1:T1');
        $hoja->setCellValue('B1', 'LISTA DE EMPAQUE / PACKING LIST');
        $hoja->getStyle('B1')->getFont()->setBold(true)->setSize(16);
        $hoja->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getRowDimension(1)->setRowHeight(24);

        $this->etiqueta($hoja, 'B2', 'EXPORTADOR / EXPORTER');
        $hoja->mergeCells('C2:J2');
        $hoja->setCellValue('C2', (string) $e->exportador_nombre);
        $hoja->getStyle('C2')->getFont()->setBold(true);

        $hoja->mergeCells('C3:J3');
        $hoja->setCellValue('C3', (string) $e->exportador_direccion);
        $hoja->getStyle('C3')->getAlignment()->setWrapText(true);

        $this->etiqueta($hoja, 'K2', 'CLIENTE / CUSTOMER');
        $hoja->mergeCells('L2:T2');
        $hoja->setCellValue('L2', (string) $e->cliente_nombre);
        $hoja->getStyle('L2')->getFont()->setBold(true);

        $hoja->mergeCells('L3:T3');
        $hoja->setCellValue('L3', (string) $e->cliente_direccion);
        $hoja->getStyle('L3')->getAlignment()->setWrapText(true);

        // FACTURA: ya no es texto libre. Sale de las FEX vinculadas, y con varias
        // facturas se listan todas separadas por « · » en la misma casilla.
        $this->etiqueta($hoja, 'B4', 'FACTURA / INVOICE');
        $hoja->mergeCells('C4:J4');
        // Explícitamente texto: un número de control como DTE-11-… no es un número,
        // y un correlativo corto se convertiría en numérico perdiendo ceros.
        $hoja->setCellValueExplicit('C4', $e->textoFactura(), DataType::TYPE_STRING);
        $hoja->getStyle('C4')->getFont()->setBold(true);

        $this->etiqueta($hoja, 'B5', 'FECHA / DATE');
        if ($e->fecha !== null) {
            $hoja->setCellValue('C5', ExcelDate::PHPToExcel($e->fecha->startOfDay()));
            $hoja->getStyle('C5')->getNumberFormat()->setFormatCode('m/d/yyyy');
        }

        $this->etiqueta($hoja, 'K5', 'FDA REG. No.');
        $hoja->mergeCells('L5:T5');
        // Texto, para no perder ceros iniciales del registro.
        $hoja->setCellValueExplicit('L5', (string) $e->fda_reg_number, DataType::TYPE_STRING);

        $hoja->getStyle('B2:T5')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function etiqueta(Worksheet $hoja, string $celda, string $texto): void
    {
        $hoja->setCellValue($celda, $texto);
        $hoja->getStyle($celda)->getFont()->setBold(true)->setSize(9);
        $hoja->getStyle($celda)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function escribirCabeceras(Worksheet $hoja): void
    {
        foreach (self::CABECERAS as $columna => [$es, $en]) {
            $hoja->setCellValue($columna.'7', $es);
            $hoja->setCellValue($columna.'8', $en);
        }

        $rango = 'B7:T8';
        $hoja->getStyle($rango)->getFont()->setBold(true)->setSize(9);
        $hoja->getStyle($rango)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $hoja->getStyle($rango)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE9EDE7');
        $hoja->getStyle('B8:T8')->getFont()->setItalic(true)->setBold(false);
        $this->bordear($hoja, $rango);
    }

    /** @return int última fila de producto escrita (o la de cabecera si no hay ninguno) */
    private function escribirProductos(Worksheet $hoja, Exportacion $e): int
    {
        $fila = self::FILA_PRIMER_PRODUCTO;

        foreach ($e->items as $item) {
            $this->escribirFilaProducto($hoja, $fila, $item);
            $fila++;
        }

        $ultima = $fila - 1;

        if ($ultima >= self::FILA_PRIMER_PRODUCTO) {
            $rango = 'B'.self::FILA_PRIMER_PRODUCTO.':T'.$ultima;
            $this->bordear($hoja, $rango);
            $hoja->getStyle($rango)->getFont()->setSize(9);
        }

        return $ultima;
    }

    private function escribirFilaProducto(Worksheet $hoja, int $r, ExportacionItem $item): void
    {
        $hoja->setCellValue('B'.$r, (int) $item->cantidad_cajas);
        $hoja->setCellValue('C'.$r, $item->descripcionCombinada());
        $hoja->setCellValue('D'.$r, (string) $item->unidad);
        $hoja->setCellValue('E'.$r, (int) $item->unidades_por_caja);
        $hoja->setCellValue('F'.$r, (float) $item->gramos_por_unidad);
        $hoja->setCellValue('G'.$r, self::FACTOR_GRAMOS_A_ONZAS);
        // Onzas y bruto vienen del SNAPSHOT, no de recalcular: el dato guardado es el
        // que se acordó con el cliente y no tiene por qué coincidir al céntimo con la
        // conversión teórica.
        $hoja->setCellValue('H'.$r, (float) $item->onzas_por_unidad);
        $hoja->setCellValue('I'.$r, "=B{$r}*E{$r}");
        $hoja->setCellValue('J'.$r, (float) $item->precio_caja);
        $hoja->setCellValue('K'.$r, "=B{$r}*J{$r}");
        $hoja->setCellValue('L'.$r, (float) $item->peso_neto_caja_kg);
        $hoja->setCellValue('M'.$r, (float) $item->peso_bruto_caja_kg);
        $hoja->setCellValue('N'.$r, "=B{$r}*L{$r}");
        $hoja->setCellValue('O'.$r, "=B{$r}*M{$r}");
        $hoja->setCellValue('P'.$r, self::FACTOR_KG_A_LB);
        $hoja->setCellValue('Q'.$r, (float) $item->peso_neto_caja_lb);
        $hoja->setCellValue('R'.$r, (float) $item->peso_bruto_caja_lb);
        $hoja->setCellValue('S'.$r, "=B{$r}*Q{$r}");
        $hoja->setCellValue('T'.$r, "=B{$r}*R{$r}");

        $hoja->getStyle('C'.$r)->getAlignment()->setWrapText(true);
        $hoja->getStyle('J'.$r.':K'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
        $hoja->getStyle('F'.$r.':H'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
        $hoja->getStyle('L'.$r.':T'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function escribirTotales(Worksheet $hoja, int $ultimaFilaProducto): void
    {
        $fila = max($ultimaFilaProducto, self::FILA_PRIMER_PRODUCTO - 1) + 1;

        $hoja->setCellValue('C'.$fila, 'TOTALES / TOTALS');

        // Sin productos no hay rango que sumar: se escriben ceros en vez de un
        // =SUM(B9:B8) que Excel abre como error de referencia.
        $hayProductos = $ultimaFilaProducto >= self::FILA_PRIMER_PRODUCTO;

        foreach (self::COLUMNAS_TOTALIZADAS as $columna) {
            $hoja->setCellValue(
                $columna.$fila,
                $hayProductos
                    ? "=SUM({$columna}".self::FILA_PRIMER_PRODUCTO.":{$columna}{$ultimaFilaProducto})"
                    : 0
            );
        }

        $rango = 'B'.$fila.':T'.$fila;
        $hoja->getStyle($rango)->getFont()->setBold(true)->setSize(9);
        $hoja->getStyle($rango)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF1F3EF');
        $hoja->getStyle('K'.$fila)->getNumberFormat()->setFormatCode('#,##0.00');
        $hoja->getStyle('N'.$fila.':T'.$fila)->getNumberFormat()->setFormatCode('#,##0.00');
        $this->bordear($hoja, $rango);
    }

    private function bordear(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FF9AA69F');
    }
}
