<?php

namespace App\Services\Ppq;

use App\Support\Dinero;
use App\Support\Dte\ReglaOrdenCompra;
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
 *
 * El PDF real del EDI sale de smalot sin etiquetas junto a los datos y con la tabla POR
 * COLUMNAS: primero todas las descripciones, después todos los costos, todos los códigos
 * de barras, todas las cantidades, etc., una por línea. El número completo va solo en su
 * línea al pie; la sala, en la primera línea «0NNN NOMBRE» después de «Número:». Se
 * siguen aceptando las etiquetas («Orden de Compra #», «Entrega en», «Creación») y la
 * tabla por filas, por si otro EDI las trae.
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

        $lineas = $this->lineas($texto);
        if ($lineas === []) {
            $lineas = $this->lineasPorColumnas($texto);
        }
        $sala = $this->sala($texto);
        $numero = $this->numero($texto, $sala, array_column($lineas, 'codigo'));
        $sala ??= $numero !== null ? OrdenCompra::salaDesde($numero) : null;

        return [
            'numero' => $numero,
            'sala_codigo' => $sala,
            // Sin «Creación», la fecha de emisión del encabezado («FECHA:» … «1/» dd/mm/aaaa).
            'fecha_creacion' => $this->fecha($texto, '(?i:Creaci[oó]n)\s*:?\s*') ?? $this->fecha($texto, '\bFECHA:(?s:.*?)'),
            'fecha_despacho' => $this->fecha($texto, '(?i:Despacho)\s*:?\s*'),
            'total' => $this->total($texto, $lineas),
            'lineas' => $lineas === [] ? null : $lineas,
            'estado' => $numero !== null ? 'leida' : 'sin_leer',
        ];
    }

    /**
     * «Orden de Compra # 261102320010394». El encabezado de la página también dice
     * «Orden de Compra # 4», por eso se exigen de 13 a 15 dígitos.
     *
     * Sin la etiqueta pegada al número, el del EDI real va solo en su línea al pie: se
     * toma la ÚLTIMA línea que sea solo un número de 13 a 15 dígitos con la forma de la
     * orden. Si tampoco, un número suelto con la sala de «Entrega en» en su lugar. La
     * forma (mes válido y sala que empieza con 0, igual a la del encabezado si se leyó)
     * deja afuera los códigos de barras de los productos (741…) y el código del cliente;
     * además, nunca es uno de los códigos de las líneas ya leídas.
     *
     * @param  list<string>  $codigos
     */
    private function numero(string $texto, ?string $sala, array $codigos): ?string
    {
        $largo = ReglaOrdenCompra::LARGO_MINIMO.','.ReglaOrdenCompra::LARGO_MAXIMO;

        if (preg_match('/Orden\s*de\s*Compra\s*(?:N[°ºo.]*\s*)?#?\s*:?\s*(\d{'.$largo.'})(?!\d)/iu', $texto, $m) === 1) {
            return $m[1];
        }

        preg_match_all('/^\h*(\d{'.$largo.'})\h*$/m', $texto, $sueltos);
        $candidatos = array_reverse($sueltos[1]);
        if ($sala !== null && preg_match_all('/(?<!\d)(\d{'.$largo.'})(?!\d)/', $texto, $todos) > 0) {
            $candidatos = [...$candidatos, ...$todos[1]];
        }

        foreach ($candidatos as $candidato) {
            if (! in_array($candidato, $codigos, true) && $this->tieneFormaDeOrden($candidato, $sala)) {
                return $candidato;
            }
        }

        return null;
    }

    /** AAMM con mes válido + sala de 4 dígitos que empieza con 0 (y es la leída, si hay). */
    private function tieneFormaDeOrden(string $candidato, ?string $sala): bool
    {
        $mes = (int) substr($candidato, 2, 2);
        $deLaOrden = OrdenCompra::salaDesde($candidato);

        return $mes >= 1 && $mes <= 12
            && $deLaOrden !== null && str_starts_with($deLaOrden, '0')
            && ($sala === null || $deLaOrden === $sala);
    }

    /**
     * «Entrega en: 0232 - NOMBRE DE LA SALA» → «0232». En el EDI real: la primera línea
     * «0240 NOMBRE…» después de «Número:» (el nombre puede seguir en la línea siguiente).
     */
    private function sala(string $texto): ?string
    {
        if (preg_match('/Entrega\s*en\s*:?\s*(\d{1,4})\s*-/iu', $texto, $m) === 1) {
            return str_pad($m[1], 4, '0', STR_PAD_LEFT);
        }

        if (preg_match('/N[uú]mero\s*:/iu', $texto, $etiqueta, PREG_OFFSET_CAPTURE) === 1
            && preg_match('/^\h*(0\d{3})\h+\S/mu', $texto, $m, 0, $etiqueta[0][1]) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * La primera fecha después de `$antes` (la etiqueta y lo que la separa de la fecha):
     * «Creación: 05-11-2026» → «2026-11-05». Null si no está o no es una fecha real.
     */
    private function fecha(string $texto, string $antes): ?string
    {
        if (preg_match('/'.$antes.'(?<!\d)(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/u', $texto, $m) !== 1) {
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
     * La tabla del EDI real, por columnas: N descripciones, N costos unitarios, N códigos
     * de barras y N cantidades pedidas, cada dato en su línea y en ese orden. Después
     * vienen bloques que no se usan (bonificación, descuentos, costo real, factor de caja,
     * unidad «UN»).
     *
     * Se ubica el bloque de códigos de barras seguidos y se exige que los otros tres
     * tengan el mismo largo: los costos justo antes (y no más), las descripciones antes de
     * los costos (hasta el encabezado de la tabla, y no más) y las cantidades justo
     * después. Si no cuadran, no se arma ninguna línea: mejor vacío que inventado.
     *
     * El total de cada línea es cantidad × costo unitario, redondeado a centavos.
     *
     * @return list<array{codigo: string, articulo: string, cantidad: string, precio: string, total: string}>
     */
    private function lineasPorColumnas(string $texto): array
    {
        $filas = array_map('trim', explode("\n", $texto));
        $esCodigo = fn (int $i) => isset($filas[$i]) && preg_match('/^\d{8,14}$/', $filas[$i]) === 1;

        for ($i = 0, $cuantas = count($filas); $i < $cuantas; $i++) {
            if (! $esCodigo($i)) {
                continue;
            }

            $fin = $i;
            while ($esCodigo($fin)) {
                $fin++;
            }

            $lineas = $this->armarColumnas($filas, $i, $fin - $i);
            if ($lineas !== null) {
                return $lineas;
            }
            $i = $fin;
        }

        return [];
    }

    /**
     * Las N líneas a partir del bloque de códigos que empieza en `$codigos`, o null si los
     * bloques de alrededor no tienen también N.
     *
     * @param  list<string>  $filas
     * @return list<array{codigo: string, articulo: string, cantidad: string, precio: string, total: string}>|null
     */
    private function armarColumnas(array $filas, int $codigos, int $n): ?array
    {
        $costos = $codigos - $n;
        $esDecimal = fn (int $i) => isset($filas[$i]) && preg_match('/^'.self::DEC.'$/', $filas[$i]) === 1;
        $esCantidad = fn (int $i) => isset($filas[$i]) && preg_match('/^'.self::NUM.'$/', $filas[$i]) === 1;

        if ($costos < 0 || $esDecimal($costos - 1)) {
            return null;
        }
        for ($k = 0; $k < $n; $k++) {
            if (! $esDecimal($costos + $k) || ! $esCantidad($codigos + $n + $k)) {
                return null;
            }
        }

        // Descripciones: las líneas con texto antes de los costos, hasta el encabezado.
        $articulos = [];
        for ($i = $costos - 1; $i >= 0 && count($articulos) <= $n; $i--) {
            if (preg_match('/^(?:Costo|Unitario|Real)$|Descripci[oó]n/iu', $filas[$i]) === 1 || preg_match('/\p{L}/u', $filas[$i]) !== 1) {
                break;
            }
            array_unshift($articulos, $filas[$i]);
        }
        if (count($articulos) !== $n) {
            return null;
        }

        $lineas = [];
        for ($k = 0; $k < $n; $k++) {
            $cantidad = $this->cifra($filas[$codigos + $n + $k]);
            $precio = $this->cifra($filas[$costos + $k]);
            $lineas[] = [
                'codigo' => $filas[$codigos + $k],
                'articulo' => $articulos[$k],
                'cantidad' => $cantidad,
                'precio' => $precio,
                'total' => Dinero::redondear(Dinero::multiplicar($cantidad, $precio)),
            ];
        }

        return $lineas;
    }

    /**
     * Total de la orden: el que trae impreso («Total: 123.45», el último de la página) o,
     * si no lo trae, la suma de los totales de las líneas. Null sin ninguno de los dos.
     *
     * El EDI real no imprime total, pero sí un «Descuento General» del 5 % sobre esa suma
     * (comprobado con órdenes reales: el monto impreso es el 5 % redondeado). Se guarda el
     * total ANTES de ese descuento: la suma de las líneas a costo unitario.
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
