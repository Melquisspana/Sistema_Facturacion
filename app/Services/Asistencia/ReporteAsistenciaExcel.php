<?php

namespace App\Services\Asistencia;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReporteAsistenciaExcel
{
    public function generar(Collection $filas): string
    {
        $libro = new Spreadsheet;
        try {
            $hoja = $libro->getActiveSheet();
            $hoja->setTitle('Asistencia');
            $hoja->fromArray(['Persona', 'Días trabajados', 'Horas', 'Tiempo']);
            $hoja->getStyle('A1:D1')->getFont()->setBold(true);
            foreach ($filas as $i => $fila) {
                $n = $i + 2;
                $hoja->setCellValueExplicit('A'.$n, $fila['nombre'], DataType::TYPE_STRING);
                $hoja->setCellValue('B'.$n, $fila['dias']);
                $hoja->setCellValue('C'.$n, $fila['horas']);
                $hoja->setCellValue('D'.$n, $fila['exacto'] ? 'Exacto' : 'Mínimo');
            }
            $hoja->getStyle('C:C')->getNumberFormat()->setFormatCode('0.00');
            foreach (range('A', 'D') as $columna) {
                $hoja->getColumnDimension($columna)->setAutoSize(true);
            }
            $semilla = tempnam(sys_get_temp_dir(), 'asistencia_');
            $ruta = $semilla.'.xlsx';
            @unlink($semilla);
            try {
                (new Xlsx($libro))->save($ruta);
            } catch (\Throwable $e) {
                @unlink($ruta);
                throw $e;
            }

            return $ruta;
        } finally {
            $libro->disconnectWorksheets();
        }
    }
}
