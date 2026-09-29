<?php

namespace App\Services\Gastos;

use InvalidArgumentException;

/**
 * Dinero en centavos enteros, y sus dos formas de salir.
 *
 * ═══════ La distinción que gobierna todo ═══════
 *
 *   {@see decimal()}  DATO          «1200.00»    value="", base, JSON de Alpine
 *   {@see mostrar()}  PRESENTACIÓN  «1,200.00»   pantalla, totales, impresos
 *
 * No son intercambiables, y confundirlas rompe cosas en direcciones opuestas:
 *
 *  · Un separador dentro de un `value=""` vuelve el campo inválido. Al enviarlo, la
 *    validación —que exige dígitos y punto— lo rechaza, y quien escribió un importe
 *    correcto recibe un error que no puede explicarse.
 *  · Un importe sin separador en pantalla se lee mal en cuanto pasa de mil: «1200.00»
 *    y «12000.00» se distinguen contando dígitos, que es justo lo que un separador
 *    evita.
 *
 * Por eso son dos métodos con nombres distintos y no un parámetro opcional: un
 * booleano en la llamada se olvida, un nombre no.
 */
final class Dinero
{
    /**
     * Lee un importe escrito por una persona y lo convierte a centavos.
     *
     * Tolera separadores de miles y espacios porque un importe se PEGA tanto como se
     * escribe, y lo que se pega suele venir con formato —de otra pantalla, de una
     * hoja de cálculo, de un mensaje—. Rechazarlo obligaría a borrar las comas a mano
     * sin que nada explique por qué.
     *
     * Lo que sigue sin aceptar: letras, símbolos, más de dos decimales y la coma como
     * separador decimal. Esto último a propósito: «1,50» es ambiguo entre países, y
     * adivinar con dinero ajeno no es aceptable.
     */
    public static function centavos(string $valor): int
    {
        $limpio = str_replace([',', ' ', "\u{00A0}"], '', trim($valor));

        if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $limpio)) {
            throw new InvalidArgumentException('Importe inválido. Use hasta dos decimales.');
        }

        [$entero, $decimal] = array_pad(explode('.', $limpio), 2, '');

        return ((int) $entero * 100) + (int) str_pad($decimal, 2, '0');
    }

    /**
     * DATO: «1200.00». Sin separadores, siempre con dos decimales.
     *
     * Es lo que va en un `value=""`, en la base y en cualquier sitio donde el importe
     * lo vaya a leer una máquina.
     */
    public static function decimal(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * PRESENTACIÓN: «1,200.00». Con separador de miles y dos decimales.
     *
     * Acepta centavos enteros o un decimal ya formado —«1200.00», como lo devuelve un
     * modelo con `decimal:2`—, porque en las vistas conviven las dos fuentes y obligar
     * a convertir antes solo produce llamadas anidadas que nadie lee.
     *
     * Un valor que no se pueda interpretar se devuelve tal cual en vez de reventar: una
     * pantalla de dinero que falla entera por un campo raro es peor que una que enseña
     * ese campo sin formato.
     */
    public static function mostrar(int|string|null $valor): string
    {
        if ($valor === null || $valor === '') {
            return '0.00';
        }

        if (is_int($valor)) {
            return number_format($valor / 100, 2, '.', ',');
        }

        try {
            return number_format(self::centavos((string) $valor) / 100, 2, '.', ',');
        } catch (InvalidArgumentException) {
            return (string) $valor;
        }
    }
}
