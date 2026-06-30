<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Utilidades del albarán de Calleja.
 */
class Albaran
{
    /**
     * Normaliza una fecha de albarán a "Y-m-d" interpretándola como salvadoreña
     * (día/mes/año). `Carbon::parse("09/06/2026")` asume formato americano (m/d) y
     * devolvería septiembre; aquí forzamos d/m/Y|d/m/y y solo caemos a parse libre
     * si no calza ningún formato local. Devuelve null si no es una fecha válida.
     */
    public static function fecha(?string $texto): ?string
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return null;
        }

        foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'd-m-y', 'd.m.Y'] as $fmt) {
            try {
                $c = Carbon::createFromFormat($fmt, $texto);
                // createFromFormat es tolerante (día 36 desborda); validar el round-trip.
                if ($c !== false && $c->format($fmt) === $texto) {
                    return $c->toDateString();
                }
            } catch (\Throwable) {
                // probar el siguiente formato
            }
        }

        return rescue(fn () => Carbon::parse($texto)->toDateString(), null, false);
    }

    /**
     * Extrae SOLO el número de albarán en formato limpio (ej. "AC01/0236/00/6359").
     *
     * Acepta VARIAS fuentes (asunto, número del PDF, snippet) y devuelve la primera
     * que contenga el código canónico de Calleja: prefijo + 3 grupos numéricos,
     * tolerando espacios alrededor de las barras y descartando un 4º grupo (el año):
     *   "Albarán AC01/0236 /00 /6359 - ELSA…"  -> "AC01/0236/00/6359"
     *   "AC01/0236/00/6359/26"                 -> "AC01/0236/00/6359"
     * Si ninguna fuente trae el código, devuelve la primera no vacía recortada.
     */
    public static function numeroLimpio(?string ...$fuentes): ?string
    {
        // 1) Primera fuente con el código canónico (al menos 2 grupos tras el prefijo;
        //    se captura un máximo de 3 para dejar fuera el "/año" cuando viene).
        foreach ($fuentes as $texto) {
            $texto = trim((string) $texto);
            if ($texto !== '' && preg_match('/([A-Za-z]{1,4}\s*\d+(?:\s*\/\s*\d+){2,3})/', $texto, $m)) {
                return preg_replace('/\s+/', '', $m[1]);
            }
        }

        // 2) Sin código reconocible: primera fuente no vacía, recortada.
        foreach ($fuentes as $texto) {
            $texto = trim((string) $texto);
            if ($texto !== '') {
                return $texto;
            }
        }

        return null;
    }

    /**
     * Deriva el código de sala (4 dígitos) del 2º segmento del número de albarán de
     * Calleja: "AC01/0236/00/6359" -> "0236". Null si no tiene ese formato. Sirve de
     * respaldo cuando la OC no se pudo parsear del PDF.
     */
    public static function salaDesdeNumero(?string $numero): ?string
    {
        $partes = explode('/', trim((string) $numero));

        if (count($partes) >= 2 && preg_match('/^\d{1,4}$/', $partes[1])) {
            return str_pad($partes[1], 4, '0', STR_PAD_LEFT);
        }

        return null;
    }
}
