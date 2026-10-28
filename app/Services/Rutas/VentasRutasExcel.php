<?php

namespace App\Services\Rutas;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class VentasRutasExcel
{
    public function generar(array $datos): string
    {
        $libro = new Spreadsheet;
        foreach (['productos' => 'Productos', 'salas' => 'Salas', 'rutas' => 'Rutas'] as $clave => $titulo) {
            $hoja = $clave === 'productos' ? $libro->getActiveSheet() : $libro->createSheet();
            $hoja->setTitle($titulo);
            // Solo nacional: la exportación (FEX) nunca se suma acá.
            $hoja->setCellValue('A1', 'Ventas nacionales (CCF − NC, sin IVA) · '.$datos['periodo']['etiqueta'].' comparado con '.$datos['periodo']['etiqueta_anterior']);
            $hoja->mergeCells('A1:F1');
            $hoja->fromArray(['Nombre', 'Cantidad', 'Venta actual', 'Venta anterior', 'Diferencia', '%'], null, 'A3');
            $fila = 4;
            foreach ($datos[$clave] as $dato) {
                $hoja->setCellValueExplicit('A'.$fila, $dato['nombre'], DataType::TYPE_STRING);
                $hoja->fromArray([$dato['cantidad'], $dato['actual'], $dato['anterior'], $dato['diferencia'], $dato['porcentaje'] === null ? null : $dato['porcentaje'] / 100], null, 'B'.$fila, true);
                $fila++;
            }
            $total = $datos['totales'];
            $hoja->fromArray(['Total', null, $total['actual'], $total['anterior'], $total['diferencia'], $total['porcentaje'] === null ? null : $total['porcentaje'] / 100], null, 'A'.$fila, true);
            $hoja->getStyle('A'.$fila.':F'.$fila)->getFont()->setBold(true);
            $fila++;
            $hoja->getStyle('A1:F3')->getFont()->setBold(true);
            if ($fila > 4) {
                $hoja->getStyle('B4:B'.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.####');
                $hoja->getStyle('C4:E'.($fila - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00;[Red]-"$"#,##0.00');
                $hoja->getStyle('F4:F'.($fila - 1))->getNumberFormat()->setFormatCode('0.0%');
            }
            foreach (range('A', 'F') as $columna) {
                $hoja->getColumnDimension($columna)->setAutoSize(true);
            }
            $hoja->freezePane('B4');
        }
        $libro->setActiveSheetIndex(0);
        $semilla = tempnam(sys_get_temp_dir(), 'ventas_rutas_');
        $ruta = $semilla.'.xlsx';
        unlink($semilla);
        try {
            (new Xlsx($libro))->save($ruta);
        } finally {
            $libro->disconnectWorksheets();
        }

        return $ruta;
    }
}
