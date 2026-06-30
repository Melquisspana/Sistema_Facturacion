<?php

namespace App\Services\Ppq;

use App\Models\PpqLote;
use App\Support\Albaran;
use App\Support\OrdenCompra;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el Excel de cobro de Calleja desde un lote PPQ: una fila por CCF/NC, en
 * el ORDEN EXACTO de columnas que pide Calleja. Toma los datos del snapshot del
 * item (origen Gmail) o del DTE local cuando existe.
 */
class ExcelCallejaExporter
{
    /** Encabezados en el orden requerido por Calleja. */
    private const COLUMNAS = [
        'Número de orden de compra',
        'Número de albarán',
        'Fecha de albarán',
        'Monto del albarán',
        'Código de generación',
        'Número de control',
        'Monto del CCF/NC',
        'Número del sello de recepción',
        'Sala de venta o CD',
        'Diferencia (CCF − albarán)',
    ];

    /** Genera el .xlsx en un archivo temporal y devuelve su ruta. */
    public function generar(PpqLote $lote): string
    {
        $lote->loadMissing(['items.dte.clienteSucursal:id,nombre,codigo', 'items.albaran']);

        $hoja = (new Spreadsheet())->getActiveSheet();
        $hoja->setTitle('PPQ Calleja');

        // Encabezados.
        foreach (self::COLUMNAS as $i => $titulo) {
            $hoja->setCellValue([$i + 1, 1], $titulo);
        }
        $hoja->getStyle('A1:J1')->getFont()->setBold(true);
        $hoja->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        // Columnas que SIEMPRE van como texto (no notación científica, conservan ceros
        // iniciales): A=OC, B=albarán, C=fecha (dd/mm/yyyy literal), E=código, F=control, H=sello, I=sala.
        foreach (['A', 'B', 'C', 'E', 'F', 'H', 'I'] as $col) {
            $hoja->getStyle($col.':'.$col)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        // Filas (orden de Calleja: CCF primero, luego NC; cada grupo por correlativo ascendente).
        $fila = 2;
        foreach ($lote->itemsOrdenados() as $item) {
            $alb = $item->albaran;
            $oc = (string) $item->numero_orden_compra;
            // Texto explícito (TYPE_STRING) en las columnas sensibles.
            $hoja->setCellValueExplicit([1, $fila], $oc, DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([2, $fila], (string) Albaran::numeroLimpio($alb?->numero_albaran), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([3, $fila], (string) optional($alb?->fecha_albaran)->format('d/m/Y'), DataType::TYPE_STRING);
            // Monto del albarán con signo (NC resta). null cuando no hay albarán.
            if ($item->montoAlbaranConSigno() !== null) {
                $hoja->setCellValue([4, $fila], $item->montoAlbaranConSigno());
            }
            $hoja->setCellValueExplicit([5, $fila], (string) ($item->codigo_generacion ?? $item->dte?->codigo_generacion), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([6, $fila], (string) ($item->numero_control ?? $item->dte?->numero_control), DataType::TYPE_STRING);
            // Monto del CCF/NC con signo: CCF suma (+), NC resta (−).
            $hoja->setCellValue([7, $fila], $item->montoDteConSigno());
            $hoja->setCellValueExplicit([8, $fila], (string) ($item->sello_recepcion ?? $item->dte?->sello_recepcion), DataType::TYPE_STRING);
            // Sala de venta: nombre comercial de la sucursal (ej. "Súper Selectos La Sultana");
            // si la sala no está registrada en la BD, cae al código de 4 dígitos.
            $salaCodigo = (string) OrdenCompra::salaDesde($oc);
            // Nombre comercial: snapshot del item → sucursal relacionada → código. Si no hay
            // nombre, cae al código de 4 dígitos (no se escribe texto de error en el archivo).
            $salaNombre = $item->salaNombre();
            $hoja->setCellValueExplicit([9, $fila], $salaNombre ?: $salaCodigo, DataType::TYPE_STRING);
            // Diferencia (CCF − albarán); solo cuando hay albarán vinculado.
            if (! $item->sin_albaran && $item->monto_albaran !== null) {
                $hoja->setCellValue([10, $fila], (float) $item->diferencia);
            }
            $fila++;
        }

        foreach (range('A', 'J') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'ppq_calleja_').'.xlsx';
        (new Xlsx($hoja->getParent()))->save($ruta);

        return $ruta;
    }

    /**
     * Nombre del archivo que pide Calleja: código de proveedor + fecha/hora de generación
     * en hora local de El Salvador, sin separadores: {codigo}{YYYYMMDDHHmm}.xlsx
     * (ej. 001065202606300350.xlsx). No afecta el contenido ni el formato del Excel.
     */
    public function nombreArchivo(PpqLote $lote): string
    {
        $codigo = (string) config('ppq.codigo_proveedor', '001065');

        return $codigo.now('America/El_Salvador')->format('YmdHi').'.xlsx';
    }
}
