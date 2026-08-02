<?php

namespace App\Support\Planta;

use Illuminate\Support\Facades\DB;

/**
 * Suma de cantidades de inventario SIN pasar por float en ningún punto.
 *
 * El problema que resuelve es del motor, no de PHP: en MySQL `SUM()` sobre un
 * `DECIMAL(14,4)` devuelve un decimal exacto, pero SQLite —donde corre la
 * suite— no tiene tipo decimal y acumula en coma flotante binaria. Sumar ahí
 * 0.1 tres veces no da 0.3, y un total de existencias que no cuadre con el
 * libro mayor por una diezmilésima es indistinguible de un descuadre real.
 *
 * La salida es SIEMPRE una cadena decimal a 4 posiciones, lista para comparar
 * con `bccomp` o para imprimir. Nunca un float.
 *
 * Es la misma técnica que emplea ReconciliacionExistenciasService; aquí vive
 * extraída para que las pantallas de consulta la compartan sin depender de un
 * servicio de escritura. Ver §5 del plan de Fase 2.
 */
final class SumaInventario
{
    /** Escala de las cantidades de inventario: DECIMAL(14,4). */
    public const ESCALA = 4;

    /** 10^ESCALA, como cadena para bcmath. */
    private const FACTOR = '10000';

    /**
     * Expresión SQL que suma la columna de forma exacta en el motor activo.
     *
     * En SQLite se escala a entero antes de sumar —aritmética de 64 bits, por
     * tanto exacta— y se devuelve a escala normal en {@see desdeSuma()}. En
     * MySQL basta `SUM()`, que ya opera en decimal.
     *
     * El nombre de columna NO se interpola desde entrada de usuario: los dos
     * únicos llamadores pasan literales del código.
     */
    public static function expresion(string $columna = 'cantidad'): string
    {
        return self::esSqlite()
            ? 'SUM(CAST(ROUND('.$columna.' * '.self::FACTOR.') AS INTEGER))'
            : 'SUM('.$columna.')';
    }

    /** Devuelve a escala normal lo que {@see expresion()} haya producido. */
    public static function desdeSuma(mixed $valor): string
    {
        if ($valor === null) {
            return self::cero();
        }

        if (self::esSqlite()) {
            return bcdiv((string) (int) $valor, self::FACTOR, self::ESCALA);
        }

        return self::aEscala($valor);
    }

    /**
     * Normaliza a la escala del inventario sin pasar por float cuando el motor
     * ya entregó una cadena decimal exacta.
     */
    public static function aEscala(mixed $valor): string
    {
        if ($valor === null) {
            return self::cero();
        }

        if (is_string($valor) && preg_match('/^-?\d+(\.\d+)?$/', $valor) === 1) {
            return bcadd($valor, '0', self::ESCALA);
        }

        if (is_int($valor)) {
            return bcadd((string) $valor, '0', self::ESCALA);
        }

        return number_format((float) $valor, self::ESCALA, '.', '');
    }

    public static function cero(): string
    {
        return bcadd('0', '0', self::ESCALA);
    }

    private static function esSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
