<?php

namespace App\Services\Reportes;

use App\Enums\EstadoDte;
use App\Models\Dte;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el Excel del "Reporte contadora" con PhpSpreadsheet. SOLO LECTURA de los
 * DTE ya existentes: no emite, no transmite, no toca correlativos ni envía correos.
 */
class ReporteContadoraExcel
{
    /** Encabezados en el orden pedido por contabilidad. */
    public const COLUMNAS = [
        'Fecha',
        'Tipo documento',
        'Cliente',
        'NIT',
        'NRC',
        'Número de control',
        'Código de generación',
        'Sello de recepción',
        'Estado',
        'Subtotal gravado',
        'IVA',
        'Retención',
        'Total',
        'Correo enviado',
        'Fecha envío correo',
        'Fecha invalidación',
        'Código evento invalidación',
        'Monto original invalidado',
    ];

    /** Columnas numéricas (montos) para aplicar formato de 2 decimales. */
    private const COLS_MONTO = ['J', 'K', 'L', 'M', 'R'];

    /** Columnas de texto (no notación científica, conservan ceros/guiones). */
    private const COLS_TEXTO = ['D', 'E', 'F', 'G', 'H', 'Q'];

    /**
     * @param  Collection<int, Dte>  $dtes  documentos ya filtrados
     */
    public function generar(Collection $dtes): string
    {
        $hoja = (new Spreadsheet)->getActiveSheet();
        $hoja->setTitle('Reporte contadora');

        foreach (self::COLUMNAS as $i => $titulo) {
            $hoja->setCellValue([$i + 1, 1], $titulo);
        }
        $hoja->getStyle('A1:R1')->getFont()->setBold(true);
        $hoja->getStyle('A1:R1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        foreach (self::COLS_TEXTO as $col) {
            $hoja->getStyle($col.':'.$col)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $fila = 2;
        foreach ($dtes as $dte) {
            $invalidado = $dte->estado === EstadoDte::Invalidado;
            $enviado = in_array($dte->ultimo_envio_estado ?? null, ['enviado', 'simulado'], true);

            $hoja->setCellValue([1, $fila], optional($dte->fecha_emision)->format('d/m/Y'));
            $hoja->setCellValue([2, $fila], $dte->tipo_dte?->label());
            $hoja->setCellValueExplicit([3, $fila], (string) ($dte->cliente?->nombre ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([4, $fila], (string) ($dte->cliente?->num_documento ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([5, $fila], (string) ($dte->cliente?->nrc ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([6, $fila], (string) ($dte->numero_control ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([7, $fila], (string) ($dte->codigo_generacion ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit([8, $fila], (string) ($dte->sello_recepcion ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValue([9, $fila], $invalidado ? 'INVALIDADO' : $dte->estado?->label());
            $hoja->setCellValue([10, $fila], $invalidado ? 0 : (float) $dte->total_gravado);
            $hoja->setCellValue([11, $fila], $invalidado ? 0 : (float) $dte->iva);
            $hoja->setCellValue([12, $fila], $invalidado ? 0 : (float) $dte->iva_retenido);
            $hoja->setCellValue([13, $fila], $invalidado ? 0 : (float) $dte->total_pagar);
            $hoja->setCellValue([14, $fila], $enviado ? 'Sí' : 'No');
            $hoja->setCellValue([15, $fila], $enviado && $dte->ultimo_envio_fecha
                ? Carbon::parse($dte->ultimo_envio_fecha)->format('d/m/Y H:i')
                : '');

            $hoja->setCellValue([16, $fila], $invalidado ? $dte->fecha_invalidacion?->format('d/m/Y H:i') : '');
            $hoja->setCellValueExplicit([17, $fila], $invalidado ? (string) $dte->codigo_generacion_invalidacion : '', DataType::TYPE_STRING);
            $hoja->setCellValue([18, $fila], $invalidado ? (float) $dte->total_pagar : '');
            if ($invalidado) {
                $hoja->getStyle('A'.$fila.':R'.$fila)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
                $hoja->getStyle('I'.$fila)->getFont()->setBold(true);
            }

            $fila++;
        }

        // Formato de moneda en las columnas de montos (filas con datos).
        if ($fila > 2) {
            foreach (self::COLS_MONTO as $col) {
                $hoja->getStyle($col.'2:'.$col.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            }

            // Fila de totales separada por una fila vacía, para que un SUM de la columna
            // hecho a mano sobre los datos no la incluya. Suma solo aceptados: con «Estado: Todos» el
            // reporte también lista borradores o rechazados, que no son venta.
            $vigentes = $dtes->filter(fn (Dte $dte) => $dte->estado === EstadoDte::Aceptado);
            $total = $fila + 1;
            $hoja->setCellValue([9, $total], 'TOTAL aceptados (sin invalidados)');
            foreach ([10 => 'total_gravado', 11 => 'iva', 12 => 'iva_retenido', 13 => 'total_pagar'] as $col => $campo) {
                $hoja->setCellValue([$col, $total], round((float) $vigentes->sum($campo), 2));
            }
            $hoja->getStyle('I'.$total.':M'.$total)->getFont()->setBold(true);
            $hoja->getStyle('I'.$total.':M'.$total)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $hoja->getStyle('J'.$total.':M'.$total)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        // El encabezado queda fijo al desplazarse por un mes con muchos documentos.
        $hoja->freezePane('A2');

        foreach (range('A', 'R') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }

        // tempnam() CREA el archivo semilla; el writer necesita la extensión .xlsx, así
        // que se borra la semilla para no dejar un temporal huérfano por cada Excel.
        $semilla = tempnam(sys_get_temp_dir(), 'reporte_contadora_');
        $ruta = $semilla.'.xlsx';
        @unlink($semilla);
        (new Xlsx($hoja->getParent()))->save($ruta);

        return $ruta;
    }

    /**
     * Nombre del archivo: reporte_contadora_{desde}_a_{hasta}.xlsx (o _todo si no hay rango).
     */
    public function nombreArchivo(?string $desde, ?string $hasta): string
    {
        $d = $desde ?: 'inicio';
        $h = $hasta ?: 'hoy';

        return "reporte_contadora_{$d}_a_{$h}.xlsx";
    }
}
