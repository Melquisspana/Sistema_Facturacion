<?php

namespace App\Support;

use App\Facades\Ajustes;

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
     * `$posicion` es la de {@see posicionSala()} ya leída: un ciclo sobre muchas órdenes
     * la lee una vez en lugar de consultar los ajustes en cada fila.
     *
     * @param  array{0: int, 1: int}|null  $posicion
     */
    public static function salaDesde(?string $oc, ?array $posicion = null): ?string
    {
        $oc = self::normalizar($oc);
        [$prefix, $length] = $posicion ?? self::posicionSala();

        if (strlen($oc) < $prefix + $length) {
            return null;
        }

        return substr($oc, $prefix, $length);
    }

    /**
     * Dónde va la sala dentro de la OC, según los ajustes: [prefijo, largo].
     *
     * @return array{0: int, 1: int}
     */
    public static function posicionSala(): array
    {
        return [
            (int) Ajustes::entero('ppq.sala_oc_prefix', 4),
            (int) Ajustes::entero('ppq.sala_oc_length', 4),
        ];
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
     * Dos OC que parecen la misma mal tecleada: del MISMO largo y con 1 o 2 dígitos
     * distintos, posición por posición (26100218003741 ↔ 20100218003741). Iguales no
     * cuentan: eso ya es la misma OC. Solo sirve para pedir revisión, nunca para vincular.
     */
    public static function casiIgual(?string $a, ?string $b): bool
    {
        $a = self::normalizar($a);
        $b = self::normalizar($b);

        if ($a === '' || $a === $b || strlen($a) !== strlen($b)) {
            return false;
        }

        $distintos = 0;
        for ($i = 0, $largo = strlen($a); $i < $largo; $i++) {
            $distintos += $a[$i] !== $b[$i] ? 1 : 0;
        }

        return $distintos <= 2;
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
