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
        foreach (($datos['canal'] === 'exportacion' ? ['productos' => 'Presentaciones', 'clientes' => 'Clientes'] : ['productos' => 'Productos', 'salas' => 'Salas', 'rutas' => 'Rutas']) as $clave => $titulo) {
            $hoja = $clave === 'productos' ? $libro->getActiveSheet() : $libro->createSheet();
            $hoja->setTitle($titulo);
            // Cada libro contiene exclusivamente el canal seleccionado.
            $hoja->setCellValue('A1', $datos['titulo'].' (USD, sin IVA) · '.$datos['periodo']['etiqueta'].' comparado con '.$datos['periodo']['etiqueta_anterior']);
            $hoja->mergeCells('A1:G1');
            $hoja->fromArray(['Nombre', 'Cantidad', 'Venta actual', 'Venta anterior', 'Diferencia', '%', 'Mismo período año anterior'], null, 'A3');
            $fila = 4;
            foreach ($datos[$clave] as $dato) {
                $hoja->setCellValueExplicit('A'.$fila, $dato['nombre'], DataType::TYPE_STRING);
                $hoja->fromArray([$dato['cantidad'], $dato['actual'], $dato['anterior'], $dato['diferencia'], $dato['porcentaje'] === null ? null : $dato['porcentaje'] / 100], null, 'B'.$fila, true);
                $hoja->setCellValue('G'.$fila, $dato['anio_anterior']);
                $fila++;
            }
            $total = $datos['totales'];
            $hoja->fromArray(['Total', null, $total['actual'], $total['anterior'], $total['diferencia'], $total['porcentaje'] === null ? null : $total['porcentaje'] / 100], null, 'A'.$fila, true);
            $hoja->setCellValue('G'.$fila, $total['anio_anterior']);
            $hoja->getStyle('A'.$fila.':G'.$fila)->getFont()->setBold(true);
            $fila++;
            $hoja->getStyle('A1:G3')->getFont()->setBold(true);
            if ($fila > 4) {
                $hoja->getStyle('B4:B'.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.####');
                $hoja->getStyle('C4:E'.($fila - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00;[Red]-"$"#,##0.00');
                $hoja->getStyle('F4:F'.($fila - 1))->getNumberFormat()->setFormatCode('0.0%');
                $hoja->getStyle('G4:G'.($fila - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00;[Red]-"$"#,##0.00');
            }
            foreach (range('A', 'G') as $columna) {
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
