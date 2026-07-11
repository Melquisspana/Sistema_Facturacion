<?php

namespace App\Services\Exportaciones;

use App\Models\Exportacion;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel SIMPLE de ayuda para armar la factura de exportación (copiar los datos a
 * mano en Conta Portable). 4 columnas: descripción, cantidad, precio unitario y
 * total. Se calcula EN VIVO desde el snapshot de la lista.
 *
 * NO es un DTE, NO emite, NO transmite, NO toca correlativos ni Conta Portable:
 * solo genera un archivo para copiar/pegar dentro del flujo manual actual.
 */
class FacturaExportacionExcel
{
    public const COLUMNAS = ['Descripción', 'Cantidad', 'Precio unitario', 'Total'];

    public function generar(Exportacion $exportacion): string
    {
        $hoja = (new Spreadsheet())->getActiveSheet();
        $hoja->setTitle('Factura exportación');

        foreach (self::COLUMNAS as $i => $titulo) {
            $hoja->setCellValue([$i + 1, 1], $titulo);
        }
        $hoja->getStyle('A1:D1')->getFont()->setBold(true);
        $hoja->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        $fila = 2;
        foreach ($exportacion->lineasFactura() as $linea) {
            $hoja->setCellValueExplicit([1, $fila], $linea['descripcion'], DataType::TYPE_STRING);
            $hoja->setCellValue([2, $fila], $linea['cantidad']);
            $hoja->setCellValue([3, $fila], $linea['precio_unitario']);
            $hoja->setCellValue([4, $fila], $linea['total']);
            $fila++;
        }

        // Total general al pie.
        if ($fila > 2) {
            $hoja->setCellValue([3, $fila], 'Total general');
            $hoja->setCellValue([4, $fila], $exportacion->valorTotal());
            $hoja->getStyle('C'.$fila.':D'.$fila)->getFont()->setBold(true);
            $hoja->getStyle('C2:D'.$fila)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        foreach (range('A', 'D') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'factura_exportacion_').'.xlsx';
        (new Xlsx($hoja->getParent()))->save($ruta);

        return $ruta;
    }

    public function nombreArchivo(Exportacion $exportacion): string
    {
        return 'factura_exportacion_lista_'.$exportacion->id.'.xlsx';
    }
}
