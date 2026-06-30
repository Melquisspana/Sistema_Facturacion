<?php

namespace App\Support\Importacion;

use RuntimeException;
use ZipArchive;

/**
 * Lector mínimo de archivos .xlsx SIN dependencias externas.
 *
 * Un .xlsx es un ZIP de XML; este lector usa ZipArchive + SimpleXML para extraer
 * las filas de la primera hoja como arreglos [columna => valor] (A, B, C…).
 * Solo lectura; no escribe ni interpreta fórmulas.
 */
class LectorXlsx
{
    /**
     * Filas de la primera hoja: cada fila es un mapa columna→valor (solo celdas con texto).
     *
     * @return array<int, array<string, string>>
     *
     * @throws RuntimeException
     */
    public function filas(string $ruta): array
    {
        if (! is_file($ruta)) {
            throw new RuntimeException("No existe el Excel: {$ruta}");
        }

        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException("No se pudo abrir el Excel: {$ruta}");
        }

        $shared = $this->cadenasCompartidas($zip);
        $hojaXml = $this->primeraHoja($zip);
        $zip->close();

        $xml = simplexml_load_string($hojaXml);
        if ($xml === false || ! isset($xml->sheetData)) {
            throw new RuntimeException('La hoja del Excel no se pudo parsear.');
        }

        $filas = [];
        foreach ($xml->sheetData->row as $row) {
            $celdas = [];
            foreach ($row->c as $c) {
                $col = preg_replace('/[0-9]+/', '', (string) $c['r']); // "B7" → "B"
                $tipo = (string) $c['t'];

                if ($tipo === 's') {
                    $valor = $shared[(int) $c->v] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $valor = (string) ($c->is->t ?? '');
                } else {
                    $valor = (string) $c->v;
                }

                $valor = trim($valor);
                if ($valor !== '') {
                    $celdas[$col] = $valor;
                }
            }
            $filas[] = $celdas;
        }

        return $filas;
    }

    /** @return array<int, string> */
    private function cadenasCompartidas(ZipArchive $zip): array
    {
        $contenido = $zip->getFromName('xl/sharedStrings.xml');
        if ($contenido === false) {
            return [];
        }

        $xml = simplexml_load_string($contenido);
        $cadenas = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $cadenas[] = (string) $si->t;
            } else {
                $texto = '';
                foreach ($si->r as $r) {
                    $texto .= (string) $r->t;
                }
                $cadenas[] = $texto;
            }
        }

        return $cadenas;
    }

    private function primeraHoja(ZipArchive $zip): string
    {
        $contenido = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($contenido !== false) {
            return $contenido;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (str_starts_with($nombre, 'xl/worksheets/sheet')) {
                $c = $zip->getFromName($nombre);
                if ($c !== false) {
                    return $c;
                }
            }
        }

        throw new RuntimeException('No se encontró ninguna hoja en el Excel.');
    }
}
