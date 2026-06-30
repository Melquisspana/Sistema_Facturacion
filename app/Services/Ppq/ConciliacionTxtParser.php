<?php

namespace App\Services\Ppq;

/**
 * Lee el archivo TXT de pagos de Calleja (formato real, separado por ";"):
 *
 *   CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR
 *   001065;ELSA … ESPAÑA;CF;DTE03M001P001000000000000967;05-JUN-26;126.44
 *   001065;ELSA … ESPAÑA;NC;DTE05M001P001000000000000339;08-JUN-26;-5.3
 *   001065;ELSA … ESPAÑA;QD;PPQ/19891;;-121.98
 *
 * TIPO_DOCUMENTO: CF = CCF pagado, NC = nota de crédito aplicada, QD = ajuste/descuento PPQ.
 * Tolera el encoding (UTF-8/Windows-1252/ISO-8859-1) porque el nombre trae Ñ/acentos.
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
     * @return array<int, array{linea:int, tipo:string, nombre:?string, numero:?string, numeroNorm:?string, fecha:?string, valor:?float, raw:string}>
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
        return rescue(fn () => \Illuminate\Support\Carbon::parse($texto)->format('Y-m-d'), null, false);
    }

    /** "126.44" / "-5.3" / "-121.98" → float; null si vacío/no numérico. */
    private function monto(string $texto): ?float
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }
        $signo = str_starts_with($texto, '-') ? -1 : 1;
        $num = preg_replace('/[^0-9.,]/', '', $texto);
        // El último separador es el decimal; el resto, miles.
        $pos = max((int) strrpos($num, '.'), (int) strrpos($num, ','));
        if ($pos > 0) {
            $entero = preg_replace('/\D/', '', substr($num, 0, $pos));
            $frac = preg_replace('/\D/', '', substr($num, $pos + 1));
            $num = $entero.'.'.$frac;
        } else {
            $num = preg_replace('/\D/', '', $num);
        }

        return $num === '' ? null : $signo * (float) $num;
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
