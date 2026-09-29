<?php

namespace App\Services\Ppq;

use App\Support\Dinero;
use Illuminate\Support\Carbon;

/**
 * Lee el archivo TXT de pagos de Calleja (formato real, separado por ";"):
 *
 *   CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR
 *   000123;TITULAR DE EJEMPLO;CF;DTE03M001P001000000000000967;05-JUN-26;126.44
 *   000123;TITULAR DE EJEMPLO;NC;DTE05M001P001000000000000339;08-JUN-26;-5.3
 *   000123;TITULAR DE EJEMPLO;QD;PPQ/19891;;-121.98
 *
 * TIPO_DOCUMENTO: CF = CCF pagado, NC = nota de crédito aplicada, QD = ajuste/descuento PPQ.
 * Tolera el encoding (UTF-8/Windows-1252/ISO-8859-1) porque el nombre trae Ñ/acentos.
 *
 * ─────────────────────────── Los importes son CADENAS ───────────────────────────
 *
 * `valor` sale como cadena decimal exacta («-0.96»), no como `float`. Todo lo que hace el
 * conciliador con ese número —compararlo contra el importe guardado, sumar el neto del
 * archivo— va con {@see Dinero} (BCMath), y convertirlo a `float` para
 * volverlo a convertir a cadena solo agrega una oportunidad de perder centavos.
 *
 * ────────────────── Por qué el separador decimal se busca así ──────────────────
 *
 * Calleja escribe los abonos chicos SIN el cero entero: `-.96` son NOVENTA Y SEIS
 * CENTAVOS. La versión anterior calculaba la posición del separador con
 * `max((int) strrpos(...))` y exigía `> 0`: en `.96` el punto está en la posición 0, la
 * condición fallaba y se caía a la rama «sin decimales», que borraba el punto y dejaba
 * −96 dólares. Sobre el archivo real del 07/09/2026 eso inflaba el total de NC de
 * −$125.90 a −$220.94 y descuadraba el neto en $95.04.
 *
 * El fondo del error era usar `(int) false === 0` como «no encontrado», que es
 * indistinguible de «encontrado en la primera posición». Acá se distinguen explícitamente.
 */
class ConciliacionTxtParser
{
    /** Abreviaturas de mes (Oracle, ES/EN) → número, para fechas tipo "08-JUN-26". */
    private const MESES = [
        'ENE' => 1, 'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'APR' => 4,
        'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'AUG' => 8, 'SEP' => 9, 'SET' => 9,
        'OCT' => 10, 'NOV' => 11, 'DIC' => 12, 'DEC' => 12,
    ];

    /**
     * @return array<int, array{linea:int, tipo:string, nombre:?string, numero:?string, numeroNorm:?string, fecha:?string, valor:?string, raw:string}>
     */
    public function parse(string $contenido): array
    {
        $contenido = $this->aUtf8($contenido);
        $lineas = preg_split('/\r\n|\r|\n/', $contenido) ?: [];

        $filas = [];
        foreach ($lineas as $i => $linea) {
            $raw = trim($linea);
            if ($raw === '') {
                continue;
            }

            $cols = array_map('trim', explode(';', $raw));

            // Encabezado: lo salta (no es una fila de datos).
            $tipo = mb_strtoupper($cols[2] ?? '');
            if ($tipo === 'TIPO_DOCUMENTO' || mb_strtoupper($cols[0] ?? '') === 'CODIGO_PROVEEDOR') {
                continue;
            }
            // Línea sin las columnas mínimas: se ignora.
            if (count($cols) < 6 || $tipo === '') {
                continue;
            }

            $numero = $cols[3] ?? '';
            $filas[] = [
                'linea' => $i + 1,
                'tipo' => $tipo,                                  // CF | NC | QD | …
                'nombre' => $cols[1] !== '' ? $cols[1] : null,
                'numero' => $numero !== '' ? $numero : null,
                'numeroNorm' => self::normalizarNumero($numero),
                'fecha' => $this->fecha($cols[4] ?? ''),          // Y-m-d o null
                'valor' => $this->monto($cols[5] ?? ''),
                'raw' => $raw,
            ];
        }

        return $filas;
    }

    /**
     * Normaliza un número de documento para comparar: solo alfanuméricos en mayúscula.
     * Así "DTE03M001P001000000000000967" == "DTE-03-M001P001-000000000000967".
     */
    public static function normalizarNumero(?string $valor): ?string
    {
        $limpio = preg_replace('/[^A-Za-z0-9]/', '', (string) $valor);

        return $limpio === '' ? null : mb_strtoupper($limpio);
    }

    /** Convierte "08-JUN-26" (u otras variantes) a Y-m-d; null si no se reconoce. */
    private function fecha(string $texto): ?string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[-\/ ]([A-Za-z]{3})[-\/ ](\d{2,4})$/', $texto, $m)) {
            $mes = self::MESES[mb_strtoupper($m[2])] ?? null;
            if ($mes !== null) {
                $anio = (int) $m[3];
                $anio += $anio < 100 ? 2000 : 0;

                return sprintf('%04d-%02d-%02d', $anio, $mes, (int) $m[1]);
            }
        }

        // Fallback tolerante (Y-m-d, d/m/Y…); si no, null.
        return rescue(fn () => Carbon::parse($texto)->format('Y-m-d'), null, false);
    }

    /**
     * "126.44" / "-5.3" / "-.96" / "1,234.56" → cadena decimal exacta; null si vacío o no
     * numérico. Ver la nota de la clase sobre por qué NO devuelve `float` y por qué el
     * separador se localiza distinguiendo «no hay» de «está en la posición 0».
     */
    private function monto(string $texto): ?string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        $negativo = str_starts_with($texto, '-');
        $num = (string) preg_replace('/[^0-9.,]/', '', $texto);
        if ($num === '') {
            return null;
        }

        // Último separador = el decimal; los anteriores son de miles. -1 significa «no hay
        // separador», que es distinto de «está en la posición 0» (`.96`).
        $punto = strrpos($num, '.');
        $coma = strrpos($num, ',');
        $sep = max($punto === false ? -1 : $punto, $coma === false ? -1 : $coma);

        if ($sep >= 0) {
            $entero = (string) preg_replace('/\D/', '', substr($num, 0, $sep));
            $decimales = (string) preg_replace('/\D/', '', substr($num, $sep + 1));
        } else {
            $entero = (string) preg_replace('/\D/', '', $num);
            $decimales = '';
        }

        // Sin un solo dígito a ningún lado no hay número (un ";.;" suelto, por ejemplo).
        if ($entero === '' && $decimales === '') {
            return null;
        }

        $entero = ltrim($entero, '0');
        if ($entero === '') {
            $entero = '0';
        }

        $valor = $decimales === '' ? $entero : $entero.'.'.$decimales;

        // «-0.00» es cero: el signo sobra y guardarlo solo produce comparaciones raras.
        $esCero = $entero === '0' && rtrim($decimales, '0') === '';

        return $negativo && ! $esCero ? '-'.$valor : $valor;
    }

    /** Asegura UTF-8 (el TXT puede venir en Windows-1252/ISO-8859-1 por la Ñ/acentos). */
    private function aUtf8(string $contenido): string
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido; // quita BOM
        if (mb_check_encoding($contenido, 'UTF-8')) {
            return $contenido;
        }

        return mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252, ISO-8859-1');
    }
}
