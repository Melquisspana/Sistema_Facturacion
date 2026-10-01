<?php

namespace App\Support;

/**
 * El número de control de un DTE, descompuesto: `DTE-03-M001P002-000000000090059` son el
 * TIPO (03), el ESTABLECIMIENTO (M001), el PUNTO DE VENTA (P002) y el CORRELATIVO.
 *
 * ──────────────────── Por qué hace falta guardar estas piezas ────────────────────
 *
 * Para CRUZAR documentos el número se normaliza a alfanuméricos ({@see IdentidadPpq}), y
 * eso está bien: es lo que permite que `DTE-03-…-119` del sistema y `DTE03…119` del TXT de
 * Calleja sean el mismo documento. Pero la normalización es de ida y vuelta solo para
 * comparar: quien mira la bandeja necesita saber de qué serie es el documento, porque el
 * correlativo `0986` existe en P001 (los históricos de Conta Portable) y en P002 (los
 * nuestros), y son documentos distintos.
 *
 * Por eso el establecimiento y el punto de venta se extraen una vez y se guardan aparte,
 * en vez de volver a sacarlos del número cada vez que alguien los necesita.
 *
 * Deliberadamente TOLERANTE: devuelve null antes que adivinar. Un número que no tiene la
 * forma esperada —un histórico tecleado a mano, por ejemplo— no gana un establecimiento
 * inventado; simplemente no tiene, y la pantalla lo muestra vacío.
 */
final class NumeroControl
{
    /**
     * `DTE-03-M001P002-000000000090059`, tolerando separadores y espacios. El bloque de
     * serie es una letra + dígitos, repetido: `M001P002`.
     */
    private const PATRON = '/^DTE[-\s]*(\d{2})[-\s]*([A-Z]\d{3,4})([A-Z]\d{3,4})[-\s]*(\d+)$/i';

    private function __construct(
        public readonly string $tipoDte,
        public readonly string $establecimiento,
        public readonly string $puntoVenta,
        public readonly string $correlativo,
    ) {}

    /** Interpreta el número; null si no tiene la forma de un número de control. */
    public static function desde(?string $numero): ?self
    {
        $texto = strtoupper(trim((string) $numero));

        if ($texto === '' || ! preg_match(self::PATRON, $texto, $m)) {
            return null;
        }

        return new self(
            tipoDte: $m[1],
            establecimiento: $m[2],
            puntoVenta: $m[3],
            correlativo: $m[4],
        );
    }

    /** Código de establecimiento (`M001`), o null si el número no lo trae. */
    public static function establecimiento(?string $numero): ?string
    {
        return self::desde($numero)?->establecimiento;
    }

    /** Código de punto de venta (`P002`), o null si el número no lo trae. */
    public static function puntoVenta(?string $numero): ?string
    {
        return self::desde($numero)?->puntoVenta;
    }

    /** Serie completa (`M001P002`) para mostrar de un vistazo de qué correlativo es. */
    public static function serie(?string $numero): ?string
    {
        $partes = self::desde($numero);

        return $partes === null ? null : $partes->establecimiento.$partes->puntoVenta;
    }

    /** Correlativo como entero, para ordenar. 0 si no se reconoce. */
    public static function correlativo(?string $numero): int
    {
        return (int) (self::desde($numero)?->correlativo ?? 0);
    }
}
