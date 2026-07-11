<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el Excel de "Documentos recibidos" con PhpSpreadsheet. SOLO LECTURA de los
 * registros locales: no toca el buzón, no envía correos, no toca DTE emitidos.
 */
class DocumentosRecibidosExcel
{
    /** Encabezados en el orden pedido. */
    public const COLUMNAS = [
        'Fecha correo',
        'Fecha DTE',
        'Emisor',
        'NIT',
        'NRC',
        'Tipo documento',
        'Número de control',
        'Código de generación',
        'Sello de recepción',
        'Total',
        'Estado',
        'Tiene PDF',
        'Tiene JSON',
    ];

    /** Columnas de texto (no notación científica, conservan ceros/guiones). */
    private const COLS_TEXTO = ['C', 'D', 'E', 'G', 'H', 'I'];

    /**
     * @param  Collection<int, DocumentoRecibido>  $documentos
     */
    public function generar(Collection $documentos): string
    {
        $hoja = (new Spreadsheet())->getActiveSheet();
        $hoja->setTitle('Documentos recibidos');

        foreach (self::COLUMNAS as $i => $titulo) {
            $hoja->setCellValue([$i + 1, 1], $titulo);
        }
        $hoja->getStyle('A1:M1')->getFont()->setBold(true);
        $hoja->getStyle('A1:M1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        foreach (self::COLS_TEXTO as $col) {
            $hoja->getStyle($col.':'.$col)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $fila = 2;
        foreach ($documentos as $doc) {
            $hoja->setCellValue([1, $fila], optional($doc->fecha_correo)->format('d/m/Y'));
            $hoja->setCellValue([2, $fila], optional($doc->fecha_dte)->format('d/m/Y'));
            $hoja->setCellValueExplicit([3, $fila], (string) ($doc->emisor_nombre ?? $doc->remitente ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([4, $fila], (string) ($doc->emisor_nit ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([5, $fila], (string) ($doc->emisor_nrc ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValue([6, $fila], $doc->tipoLabel());
            $hoja->setCellValueExplicit([7, $fila], (string) ($doc->numero_control ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([8, $fila], (string) ($doc->codigo_generacion ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([9, $fila], (string) ($doc->sello_recepcion ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValue([10, $fila], $doc->total !== null ? (float) $doc->total : null);
            $hoja->setCellValue([11, $fila], ucfirst((string) $doc->estado));
            $hoja->setCellValue([12, $fila], $doc->tiene_pdf ? 'Sí' : 'No');
            $hoja->setCellValue([13, $fila], $doc->tiene_json ? 'Sí' : 'No');
            $fila++;
        }

        if ($fila > 2) {
            $hoja->getStyle('J2:J'.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        foreach (range('A', 'M') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'documentos_recibidos_').'.xlsx';
        (new Xlsx($hoja->getParent()))->save($ruta);

        return $ruta;
    }

    public function nombreArchivo(string $etiqueta): string
    {
        return 'documentos_recibidos_'.$etiqueta.'.xlsx';
    }
}
