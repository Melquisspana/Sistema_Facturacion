<?php

namespace App\Support\Dte;

use InvalidArgumentException;

/**
 * Construye el número de control del DTE a partir de sus partes.
 *
 * Utilidad PURA y PREPARATORIA: el formato es configurable en config/dte.php y
 * está sujeto a validación contra el esquema/normativa oficial del MH antes de
 * emitir. NO reemplaza por ahora a `numero_interno` (que sigue rigiendo el flujo).
 *
 * Formato por defecto: DTE-{tipo}-{estab}{puntoVenta}-{correlativo a 15 dígitos}
 * Ej.: DTE-03-M001P001-000000000000001
 */
class NumeroControlBuilder
{
    /**
     * @param  string  $tipoDte          Código CAT-002 de 2 dígitos (ej. "03")
     * @param  string  $codEstablecimiento  Código del establecimiento (1–4 caracteres)
     * @param  string  $codPuntoVenta    Código del punto de venta (1–4 caracteres)
     * @param  int     $correlativo      Número correlativo positivo
     *
     * @throws InvalidArgumentException si alguna parte no cumple el formato
     */
    public static function construir(string $tipoDte, string $codEstablecimiento, string $codPuntoVenta, int $correlativo): string
    {
        $tipoDte = trim($tipoDte);
        $codEstablecimiento = trim($codEstablecimiento);
        $codPuntoVenta = trim($codPuntoVenta);

        if (! preg_match('/^\d{2}$/', $tipoDte)) {
            throw new InvalidArgumentException('El tipo de DTE debe ser de 2 dígitos (CAT-002).');
        }
        self::validarSegmento($codEstablecimiento, 'establecimiento');
        self::validarSegmento($codPuntoVenta, 'punto de venta');
        if ($correlativo < 1) {
            throw new InvalidArgumentException('El correlativo debe ser un entero positivo.');
        }

        $longitud = (int) config('dte.json.numero_control_longitud_correlativo', 15);
        $formato = (string) config('dte.json.numero_control_formato', 'DTE-{tipo}-{establecimiento}{puntoVenta}-{correlativo}');

        return strtr($formato, [
            '{tipo}' => $tipoDte,
            '{establecimiento}' => str_pad($codEstablecimiento, 4, '0', STR_PAD_LEFT),
            '{puntoVenta}' => str_pad($codPuntoVenta, 4, '0', STR_PAD_LEFT),
            '{correlativo}' => str_pad((string) $correlativo, $longitud, '0', STR_PAD_LEFT),
        ]);
    }

    private static function validarSegmento(string $valor, string $nombre): void
    {
        if ($valor === '' || mb_strlen($valor) > 4) {
            throw new InvalidArgumentException("El código de {$nombre} debe tener entre 1 y 4 caracteres.");
        }
    }
}
