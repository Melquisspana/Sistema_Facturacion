<?php

namespace App\Support\Dte;

use App\Support\Dinero;

/**
 * Convierte un monto a letras en español, formato MH:
 *   113.00 → "CIENTO TRECE 00/100 DÓLARES"
 *    18.50 → "DIECIOCHO 50/100 DÓLARES"
 *     0.00 → "CERO 00/100 DÓLARES"
 *
 * Redondea a 2 decimales. Maneja enteros, centavos, cero y valores grandes
 * razonables (hasta cientos de millones).
 */
class NumeroALetras
{
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE',
        'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS',
        'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = [
        '', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS',
    ];

    public static function convertir(string|int|float $monto, string $moneda = 'DÓLARES'): string
    {
        $valor = Dinero::redondear($monto, 2);              // "113.00"
        [$entero, $centavos] = explode('.', $valor);
        $entero = (int) $entero;

        $letras = $entero === 0 ? 'CERO' : trim(self::enteroALetras($entero));

        return $letras.' '.$centavos.'/100 '.$moneda;
    }

    private static function enteroALetras(int $n): string
    {
        if ($n === 0) {
            return '';
        }

        $millones = intdiv($n, 1_000_000);
        $miles = intdiv($n % 1_000_000, 1_000);
        $resto = $n % 1_000;

        $partes = [];

        if ($millones > 0) {
            $partes[] = $millones === 1
                ? 'UN MILLÓN'
                : self::apocopar(self::grupoALetras($millones)).' MILLONES';
        }

        if ($miles > 0) {
            $partes[] = $miles === 1
                ? 'MIL'
                : self::apocopar(self::grupoALetras($miles)).' MIL';
        }

        if ($resto > 0) {
            $partes[] = self::grupoALetras($resto);
        }

        return implode(' ', $partes);
    }

    /** Convierte 1..999 a letras. */
    private static function grupoALetras(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }

        $centena = intdiv($n, 100);
        $decena = $n % 100;

        $texto = self::CENTENAS[$centena];

        if ($decena > 0) {
            $texto = trim($texto.' '.self::decenaALetras($decena));
        }

        return trim($texto);
    }

    private static function decenaALetras(int $n): string
    {
        if ($n <= 29) {
            return self::UNIDADES[$n];
        }

        $d = intdiv($n, 10);
        $u = $n % 10;

        return $u === 0 ? self::DECENAS[$d] : self::DECENAS[$d].' Y '.self::UNIDADES[$u];
    }

    /** "...UNO" → "...UN" antes de MIL/MILLONES (ej. VEINTIUNO → VEINTIÚN). */
    private static function apocopar(string $texto): string
    {
        if ($texto === 'UNO') {
            return 'UN';
        }
        if (str_ends_with($texto, 'VEINTIUNO')) {
            return substr($texto, 0, -9).'VEINTIÚN';
        }
        if (str_ends_with($texto, ' UNO')) {
            return substr($texto, 0, -4).' UN';
        }

        return $texto;
    }
}
