<?php

namespace App\Support\Dte;

use Illuminate\Support\Str;

/**
 * Código de generación del DTE: UUID v4 en MAYÚSCULAS (formato exigido por el MH).
 *
 * Utilidad PURA y preparatoria. NO se asigna automáticamente a ningún documento
 * todavía: la asignación transaccional llegará con el servicio de generación JSON.
 */
class CodigoGeneracion
{
    /** Patrón UUID v4 en mayúsculas. */
    private const PATRON = '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/';

    /** Genera un UUID v4 en mayúsculas. */
    public static function generar(): string
    {
        return strtoupper((string) Str::uuid());
    }

    /** Valida que una cadena sea un UUID v4 en mayúsculas. */
    public static function esValido(string $valor): bool
    {
        return (bool) preg_match(self::PATRON, $valor);
    }
}
