<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Formateo de fechas para PPQ a d/m/Y (ej. 15/06/2026). Tolerante: acepta Carbon, string
 * en cualquier formato razonable (Y-m-d, d/m/Y…) o null. Si no logra interpretar el valor,
 * devuelve el original tal cual en vez de romper o inventar una fecha.
 */
class Fecha
{
    public static function dmy(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance($valor)->format('d/m/Y');
        }

        $texto = trim((string) ($valor ?? ''));
        if ($texto === '') {
            return null;
        }

        // Si ya viene como d/m/Y se respeta tal cual (Carbon::parse lo leería como m/d americano).
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $texto)) {
            return $texto;
        }

        // Resto (Y-m-d, ISO, datetime…): se interpreta; si falla, se devuelve el original.
        return rescue(fn () => Carbon::parse($texto)->format('d/m/Y'), $texto, false);
    }
}
