<?php

namespace App\Support;

/**
 * Utilidades para la orden de compra (OC) de Calleja en el módulo PPQ.
 *
 * La sala son los `length` dígitos que vienen JUSTO DESPUÉS del prefijo YYMM
 * (4 dígitos) de la OC. Siempre 4 dígitos, empieza con 0, se trata como texto.
 * Ejemplos reales:
 *   2606026002401  -> sala 0260
 *   26060236004586 -> sala 0236
 *   26050039004820 -> sala 0039
 *   26050230001794 -> sala 0230
 *
 * El offset (prefijo) y la longitud son configurables (config/ppq.php).
 */
class OrdenCompra
{
    /**
     * Extrae el código de sala (4 dígitos, conservando el cero inicial) desde la OC:
     * los `length` dígitos que siguen al prefijo YYMM (`prefix` dígitos). Devuelve
     * null si la OC es demasiado corta. Ej.: 2606026002401 → 0260.
     */
    public static function salaDesde(?string $oc): ?string
    {
        $oc = self::normalizar($oc);
        $prefix = (int) config('ppq.sala_oc_prefix', 4);
        $length = (int) config('ppq.sala_oc_length', 4);

        if (strlen($oc) < $prefix + $length) {
            return null;
        }

        return substr($oc, $prefix, $length);
    }

    /** Alias descriptivo. */
    public static function salaDesdeOc(?string $oc): ?string
    {
        return self::salaDesde($oc);
    }

    /** Normaliza una OC: solo dígitos (descarta espacios, guiones, etc.). */
    public static function normalizar(?string $oc): string
    {
        return preg_replace('/\D/', '', (string) $oc);
    }

    /**
     * Últimos N dígitos significativos de un número de control, para la búsqueda
     * por "últimos 4". Ej.: DTE-03-M001P001-0000000000000986 -> "986" (o "0986").
     */
    public static function ultimosDigitos(?string $numeroControl, int $n = 4): string
    {
        $digitos = preg_replace('/\D/', '', (string) $numeroControl);

        return $digitos === '' ? '' : substr($digitos, -$n);
    }
}
