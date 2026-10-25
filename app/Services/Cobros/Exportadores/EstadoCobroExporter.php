<?php

namespace App\Services\Cobros\Exportadores;

use App\Services\Cobros\EstadoCobroDesdeInicio;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/** Excel del estado de cobro ({@see EstadoCobroDesdeInicio}): hojas CCF y NC. */
class EstadoCobroExporter
{
    public function escribir(array $reporte): string
    {
        $libro = new Spreadsheet;
        $estados = ['pagado' => 'Pagado', 'diferencia' => 'Pagado con diferencia', 'no_pagado' => 'No pagado', 'en_revision' => 'En revisión',
            'descontada' => 'Descontada', 'no_descontada' => 'No descontada', 'pronto_pago' => 'Pronto pago'];
        $ruta = false;
        try {
            foreach (['ccf' => 'CCF', 'nc' => 'NC'] as $grupo => $titulo) {
                $hoja = $grupo === 'ccf' ? $libro->getActiveSheet() : $libro->createSheet();
                $hoja->setTitle($titulo);
                $hoja->fromArray($grupo === 'ccf' ? ['Documento', 'Fecha', 'Estado', 'A cobrar', 'Cobrado', 'Diferencia', 'TXT', 'PPQ'] : ['NC', 'Fecha', 'CCF relacionado', 'Estado', 'Monto', 'TXT', 'PPQ']);
                foreach ($reporte[$grupo] as $indice => $fila) {
                    $doc = $fila[$grupo === 'ccf' ? 'documento' : 'nc'];
                    $datos = $grupo === 'ccf'
                        ? [$doc->numero_control, $doc->fecha_emision?->format('d/m/Y'), $estados[$fila['estado']], $fila['a_cobrar'], $fila['cobrado'], $fila['diferencia'], implode(' · ', $fila['txt']), implode(' · ', $fila['ppq'])]
                        : [$doc->numero_control, $doc->fecha_emision?->format('d/m/Y'), $fila['ccf'], $estados[$fila['estado']], $doc->total_pagar, implode(' · ', $fila['txt']), implode(' · ', $fila['ppq'])];
                    foreach ($datos as $columna => $valor) {
                        // Los nombres de TXT y las referencias son texto, nunca fórmulas.
                        $esMonto = $grupo === 'ccf' ? in_array($columna, [3, 4, 5], true) : $columna === 4;
                        $hoja->setCellValueExplicit([$columna + 1, $indice + 2], $esMonto ? (float) $valor : (string) $valor, $esMonto ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                        if ($esMonto) {
                            $hoja->getStyle([$columna + 1, $indice + 2])->getNumberFormat()->setFormatCode('#,##0.00');
                        }
                    }
                }
                $hoja->getStyle('1:1')->getFont()->setBold(true);
                $hoja->freezePane('A2');
                foreach (range('A', $grupo === 'ccf' ? 'H' : 'G') as $columna) {
                    $hoja->getColumnDimension($columna)->setAutoSize(true);
                }
            }
            $libro->setActiveSheetIndex(0);
            $ruta = tempnam(sys_get_temp_dir(), 'cobros_estado_');
            if ($ruta === false) {
                throw new RuntimeException('No se pudo crear el archivo temporal del estado de cobro.');
            }
            (new Xlsx($libro))->save($ruta);

            return $ruta;
        } catch (Throwable $e) {
            if ($ruta !== false) {
                @unlink($ruta);
            }
            throw $e;
        } finally {
            $libro->disconnectWorksheets();
        }
    }
}
