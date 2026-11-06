<?php

namespace App\Services\Ppq;

use App\Support\Dinero;
use App\Support\OrdenCompra;
use Smalot\PdfParser\Parser;

/**
 * Lee la orden de compra de Calleja (PDF del EDI de Almamater) que llega a la etiqueta
 * «Ordenes» de Gmail: número, sala, fechas, total y líneas.
 *
 * El texto que saca smalot/pdfparser no siempre respeta el orden ni los espacios del
 * PDF, así que cada dato se busca por su etiqueta sobre el texto completo, con
 * expresiones tolerantes. Lo que no se encuentra queda en null; si falta el NÚMERO, la
 * orden vuelve como `sin_leer` para revisarla a mano. Nunca se descarta.
 *
 * Forma del número: AAMM + sala de 4 dígitos (empieza con 0) + correlativo, de 13 a 15
 * dígitos en total (ver ReglaOrdenCompra).
 */
class OrdenCompraParser
{
    /** Separador entre columnas de una línea: espacios, tabuladores o barras. */
    private const SEP = '[\h|]+';

    /** Número con decimales opcionales y comas de miles. */
    private const NUM = '\d[\d,]*(?:\.\d+)?';

    /** Número con decimales obligatorios (precio, descuento, impuesto y total de línea). */
    private const DEC = '\d[\d,]*\.\d+';

    /**
     * @return array{numero: ?string, sala_codigo: ?string, fecha_creacion: ?string, fecha_despacho: ?string, total: ?string, lineas: ?list<array<string, mixed>>, estado: string}
     */
    public function desdePdf(string $pdf): array
    {
        try {
            $texto = (new Parser)->parseContent($pdf)->getText();
        } catch (\Throwable) {
            $texto = '';
        }

        return $this->desdeTexto($texto);
    }

    /**
     * @return array{numero: ?string, sala_codigo: ?string, fecha_creacion: ?string, fecha_despacho: ?string, total: ?string, lineas: ?list<array<string, mixed>>, estado: string}
     */
    public function desdeTexto(string $texto): array
    {
        // Espacios duros y retornos de Windows a su forma común.
        $texto = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', "\n", "\n"], $texto);

        $sala = $this->sala($texto);
        $numero = $this->numero($texto, $sala);
        $sala ??= $numero !== null ? OrdenCompra::salaDesde($numero) : null;
        $lineas = $this->lineas($texto);

        return [
            'numero' => $numero,
            'sala_codigo' => $sala,
            'fecha_creacion' => $this->fecha($texto, 'Creaci[oó]n'),
            'fecha_despacho' => $this->fecha($texto, 'Despacho'),
            'total' => $this->total($texto, $lineas),
            'lineas' => $lineas === [] ? null : $lineas,
            'estado' => $numero !== null ? 'leida' : 'sin_leer',
        ];
    }

    /**
     * «Orden de Compra # 261102320010394». El encabezado de la página también dice
     * «Orden de Compra # 4», por eso se exigen de 13 a 15 dígitos.
     *
     * Si la etiqueta no aparece pegada al número (texto desordenado), se acepta un número
     * suelto de 13 a 15 dígitos solo si lleva la sala de «Entrega en» en su lugar y un
     * mes válido: así no se confunde con los códigos de barras de los productos ni con el
     * código del cliente, que también tienen 13 dígitos.
     */
    private function numero(string $texto, ?string $sala): ?string
    {
        if (preg_match('/Orden\s*de\s*Compra\s*(?:N[°ºo.]*\s*)?#?\s*:?\s*(\d{13,15})(?!\d)/iu', $texto, $m) === 1) {
            return $m[1];
        }

        if ($sala === null || preg_match_all('/(?<!\d)(\d{13,15})(?!\d)/', $texto, $todos) < 1) {
            return null;
        }

        foreach ($todos[1] as $candidato) {
            $mes = (int) substr($candidato, 2, 2);
            if ($mes >= 1 && $mes <= 12 && substr($candidato, 4, 4) === $sala) {
                return $candidato;
            }
        }

        return null;
    }

    /** «Entrega en: 0232 - NOMBRE DE LA SALA» → «0232». */
    private function sala(string $texto): ?string
    {
        if (preg_match('/Entrega\s*en\s*:?\s*(\d{1,4})\s*-/iu', $texto, $m) !== 1) {
            return null;
        }

        return str_pad($m[1], 4, '0', STR_PAD_LEFT);
    }

    /** «Creación: 05-11-2026» → «2026-11-05». Null si no está o no es una fecha real. */
    private function fecha(string $texto, string $etiqueta): ?string
    {
        if (preg_match('/'.$etiqueta.'\s*:?\s*(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/iu', $texto, $m) !== 1) {
            return null;
        }

        [$dia, $mes, $anio] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return checkdate($mes, $dia, $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, $dia) : null;
    }

    /**
     * Líneas de la tabla: [código interno] código de barras, artículo, cantidad pedida,
     * unidades, precio, descuento, impuesto y total. Las seis columnas numéricas se
     * anclan al final de la línea, así el nombre del artículo puede llevar números.
     *
     * @return list<array{codigo: string, articulo: string, cantidad: string, precio: string, total: string}>
     */
    private function lineas(string $texto): array
    {
        $s = self::SEP;
        $patron = '/^[\h|]*(?:\d{1,10}'.$s.')?(\d{8,14})'.$s.'(.+?)'.$s.'('.self::NUM.')'.$s.'('.self::NUM.')'
            .$s.'('.self::DEC.')'.$s.'('.self::DEC.')'.$s.'('.self::DEC.')'.$s.'('.self::DEC.')[\h|]*$/mu';

        if (preg_match_all($patron, $texto, $filas, PREG_SET_ORDER) < 1) {
            return [];
        }

        return array_map(fn (array $f) => [
            'codigo' => $f[1],
            'articulo' => trim(str_replace('|', ' ', $f[2])),
            'cantidad' => $this->cifra($f[3]),
            'precio' => $this->cifra($f[5]),
            'total' => $this->cifra($f[8]),
        ], $filas);
    }

    /**
     * Total de la orden: el que trae impreso («Total: 123.45», el último de la página) o,
     * si no lo trae, la suma de los totales de las líneas. Null sin ninguno de los dos.
     *
     * @param  list<array<string, string>>  $lineas
     */
    private function total(string $texto, array $lineas): ?string
    {
        if (preg_match_all('/\bTotal(?:\h+(?:General|Orden|a\h+Pagar))?\h*:?\h*\$?\h*('.self::DEC.')(?![\d.])/iu', $texto, $m) > 0) {
            return Dinero::redondear($this->cifra(end($m[1])));
        }

        if ($lineas === []) {
            return null;
        }

        return Dinero::redondear(array_reduce($lineas, fn (string $suma, array $l) => Dinero::sumar($suma, $l['total']), '0'));
    }

    private function cifra(string $valor): string
    {
        return str_replace(',', '', $valor);
    }
}
