<?php

namespace App\Support;

/**
 * El número canónico de un albarán de Calleja, descompuesto: `AC04/0033/00/3209` son el
 * TIPO, la SALA, un bloque fijo y el NÚMERO. El Excel del cliente pide esos tres datos en
 * columnas separadas (G, F y H), así que una sola captura bien parseada llena tres
 * columnas y evita pedirle al operador lo mismo tres veces.
 *
 * Acepta también el nombre del archivo que manda Calleja, que trae las mismas piezas en
 * otro orden: `26-04-0045-00-002270-AC02-0001.PDF` -> AA-MM-SALA-00-NÚMERO-TIPO-SECUENCIA.
 *
 * Deliberadamente NO valida qué tipos existen: los códigos válidos los declara cada
 * cliente en su perfil ({@see \App\Models\ClientePerfilTipoNc}), no esta clase.
 * {@see \App\Support\Albaran} sigue siendo el lugar de las utilidades del albarán de
 * ENTREGA (AC01) usadas por PPQ; acá solo vive el desglose.
 */
final class NumeroAlbaran
{
    private function __construct(
        public readonly string $canonico,
        public readonly string $tipo,
        public readonly ?string $sala,
        public readonly string $numero,
    ) {}

    /**
     * Interpreta el texto que escribió el operador. Devuelve null si no se reconoce
     * ninguna forma; nunca adivina a medias.
     */
    public static function desde(?string $texto): ?self
    {
        $texto = strtoupper(trim((string) $texto));

        if ($texto === '') {
            return null;
        }

        return self::desdeCanonico($texto) ?? self::desdeNombreArchivo($texto);
    }

    /** Forma `AC04/0033/00/3209`, tolerando espacios y un 4.º grupo de año al final. */
    private static function desdeCanonico(string $texto): ?self
    {
        if (! preg_match('#^([A-Z]{2,4}\d{0,2})\s*/\s*(\d{1,6})\s*/\s*(\d{1,4})\s*/\s*(\d{1,10})#', $texto, $m)) {
            return null;
        }

        return self::armar($m[1], $m[2], $m[3], $m[4]);
    }

    /** Forma `26-04-0045-00-002270-AC02-0001[.PDF]`. */
    private static function desdeNombreArchivo(string $texto): ?self
    {
        if (! preg_match('#^\d{2}-\d{2}-(\d{1,6})-(\d{1,4})-(\d{1,10})-([A-Z]{2,4}\d{0,2})(?:-\d+)?#', $texto, $m)) {
            return null;
        }

        return self::armar($m[4], $m[1], $m[2], $m[3]);
    }

    private static function armar(string $tipo, string $sala, string $bloque, string $numero): self
    {
        // La sala conserva su relleno a 4 dígitos (0033, no 33); el número NO se rellena
        // porque en el Excel de Calleja va tal cual ("3209", "8489").
        $sala = str_pad(ltrim($sala, ' ') ?: '0', 4, '0', STR_PAD_LEFT);
        $numero = ltrim($numero, '0');
        $numero = $numero === '' ? '0' : $numero;

        return new self(
            canonico: $tipo.'/'.$sala.'/'.str_pad($bloque, 2, '0', STR_PAD_LEFT).'/'.$numero,
            tipo: $tipo,
            sala: $sala,
            numero: $numero,
        );
    }
}
