<?php

namespace App\Support;

/**
 * Aritmética de dinero EXACTA con BCMath (cadenas decimales). Evita los errores
 * de coma flotante (ej. 0.1 + 0.2 != 0.3). Toda operación monetaria del motor
 * DTE debe pasar por aquí.
 *
 * - Escala interna alta (8) para no perder centavos al multiplicar precios.
 * - Redondeo final half-up a 2 decimales para los importes presentables.
 */
class Dinero
{
    /** Escala interna de cálculo. */
    public const ESCALA = 8;

    /** Normaliza cualquier número a cadena decimal segura para BCMath. */
    public static function de(string|int|float $valor): string
    {
        if (is_float($valor)) {
            // Evita notación científica/binaria; suficiente precisión de entrada.
            return rtrim(rtrim(sprintf('%.'.self::ESCALA.'F', $valor), '0'), '.') ?: '0';
        }

        return (string) $valor;
    }

    public static function multiplicar(string|int|float $a, string|int|float $b): string
    {
        return bcmul(self::de($a), self::de($b), self::ESCALA);
    }

    public static function sumar(string|int|float $a, string|int|float $b): string
    {
        return bcadd(self::de($a), self::de($b), self::ESCALA);
    }

    public static function restar(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::de($a), self::de($b), self::ESCALA);
    }

    public static function dividir(string|int|float $a, string|int|float $b): string
    {
        return bcdiv(self::de($a), self::de($b), self::ESCALA);
    }

    public static function comparar(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::de($a), self::de($b), self::ESCALA);
    }

    /**
     * Redondeo half-up a N decimales (2 por defecto). Devuelve la cadena con
     * exactamente N decimales (ej. "20.00").
     */
    public static function redondear(string|int|float $valor, int $decimales = 2): string
    {
        $valor = self::de($valor);
        $ajuste = '0.'.str_repeat('0', $decimales).'5';

        if (bccomp($valor, '0', self::ESCALA) >= 0) {
            return bcadd($valor, $ajuste, $decimales);
        }

        return bcsub($valor, $ajuste, $decimales);
    }
}
