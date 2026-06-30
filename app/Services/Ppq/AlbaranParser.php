<?php

namespace App\Services\Ppq;

/**
 * Extrae los datos de un albarán de Calleja desde el PDF adjunto: número, fecha,
 * orden de compra y monto. Devuelve también una traza de debug (texto extraído,
 * regexes usadas y candidatos de monto) para poder afinar las expresiones según
 * el formato real del PDF.
 */
class AlbaranParser
{
    /** Palabras que suelen anteceder al monto total en el albarán. */
    private const KEYWORDS_MONTO = 'total|monto|valor|neto|importe|a\s*pagar|gran\s*total';

    /**
     * @return array{numero: ?string, fecha: ?string, oc: ?string, monto: ?float, debug: array<string, mixed>}
     */
    public function desdePdf(string $pdfBytes): array
    {
        $texto = '';
        $error = null;
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $texto = $parser->parseContent($pdfBytes)->getText();
        } catch (\Throwable $e) {
            $error = 'No se pudo leer el PDF: '.$e->getMessage();
        }

        return $this->desdeTexto($texto, $error);
    }

    /**
     * @return array{numero: ?string, fecha: ?string, oc: ?string, monto: ?float, debug: array<string, mixed>}
     */
    public function desdeTexto(string $texto, ?string $error = null): array
    {
        $texto = (string) $texto;
        $debug = [
            'error' => $error,
            'texto_largo' => strlen($texto),
            'texto_preview' => mb_substr(trim($texto), 0, 800),
            'regex' => [],
            'candidatos_monto' => [],
        ];

        $oc = $this->buscar($texto, '/(?:orden\s*de\s*compra|orden|o\.?\s*c\.?|OC)\D{0,15}(\d{8,})/i', $debug, 'oc');
        // Código canónico del albarán (AC01/0236/00/6359), no la palabra "Total":
        // prefijo + 3 grupos numéricos, tolerando espacios y descartando el "/año".
        $numero = $this->buscar($texto, '/([A-Za-z]{1,4}\s*\d+(?:\s*\/\s*\d+){2,3})/', $debug, 'numero');
        $numero = $numero !== null ? preg_replace('/\s+/', '', $numero) : null;
        // Fecha real dd/mm/aaaa con día 01-31 y mes 01-12 (excluye trozos del código
        // como "36/00/6359", cuyo "mes" 00 no es válido).
        $fecha = $this->buscar($texto, '#\b((?:0?[1-9]|[12]\d|3[01])[/\-.](?:0?[1-9]|1[0-2])[/\-.](?:\d{4}|\d{2}))\b#', $debug, 'fecha');
        $monto = $this->monto($texto, $debug);

        return ['numero' => $numero, 'fecha' => $fecha, 'oc' => $oc, 'monto' => $monto, 'debug' => $debug];
    }

    /** Aplica una regex, registra en debug y devuelve el grupo 1 o null. */
    private function buscar(string $texto, string $regex, array &$debug, string $clave): ?string
    {
        $ok = preg_match($regex, $texto, $m);
        $debug['regex'][$clave] = ['patron' => $regex, 'match' => $ok ? ($m[1] ?? $m[0]) : null];

        return $ok ? trim($m[1] ?? $m[0]) : null;
    }

    /**
     * Busca el monto: primero anclado a una palabra clave (Total/Monto/…), si no,
     * toma el mayor número con formato de dinero. Registra todos los candidatos.
     */
    private function monto(string $texto, array &$debug): ?float
    {
        $numeroRe = '\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})|\d+[.,]\d{2}';

        // 1) Anclado a palabra clave.
        $reKw = '/(?:'.self::KEYWORDS_MONTO.')\D{0,20}('.$numeroRe.')/i';
        $debug['regex']['monto_keyword'] = ['patron' => $reKw, 'matches' => []];
        $anclados = [];
        if (preg_match_all($reKw, $texto, $ms)) {
            foreach ($ms[1] as $raw) {
                $val = $this->normalizarMonto($raw);
                $debug['regex']['monto_keyword']['matches'][] = ['raw' => $raw, 'val' => $val];
                if ($val !== null) {
                    $anclados[] = $val;
                }
            }
        }

        // 2) Todos los números con formato de dinero (fallback).
        $reAll = '/('.$numeroRe.')/';
        $todos = [];
        if (preg_match_all($reAll, $texto, $ma)) {
            foreach ($ma[1] as $raw) {
                $val = $this->normalizarMonto($raw);
                if ($val !== null) {
                    $todos[] = $val;
                    $debug['candidatos_monto'][] = ['raw' => $raw, 'val' => $val];
                }
            }
        }

        // Preferir el ancla (el mayor de los anclados), si no, el mayor candidato.
        if ($anclados !== []) {
            return max($anclados);
        }

        return $todos !== [] ? max($todos) : null;
    }

    /** Normaliza un número de dinero a float, detectando separador decimal. */
    private function normalizarMonto(string $s): ?float
    {
        $s = preg_replace('/[^\d.,]/', '', $s);
        if ($s === '') {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            $s = (strlen($s) - $lastComma - 1) === 2 ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($lastDot !== false) {
            if ((strlen($s) - $lastDot - 1) !== 2) {
                $s = str_replace('.', '', $s);
            }
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
