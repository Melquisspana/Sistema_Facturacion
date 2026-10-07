<?php

namespace App\Support\Dte;

/**
 * Documento de identificación de las personas de un evento (responsable y solicitante de
 * la invalidación; responsable del establecimiento en la contingencia).
 *
 * Normativa 2.0, Anexo V, campos 112-117 (pp.134-135): nombre y documento según CAT-022.
 * El esquema solo exige texto de hasta 20 caracteres; aquí se normaliza al formato que el
 * MH usa en el resto de los DTE (NIT sin guiones, 14 o 9 dígitos; DUI 00000000-0) para no
 * enviar un número que Hacienda no pueda cruzar.
 */
final class DocumentoIdentidadMh
{
    /** CAT-022 «Tipo de documento de identificación». */
    public const TIPOS = [
        '36' => 'NIT',
        '13' => 'DUI',
        '37' => 'Otro',
        '03' => 'Pasaporte',
        '02' => 'Carnet de residente',
    ];

    /** Número en el formato del MH según el tipo; los demás tipos solo se recortan. */
    public static function normalizarNumero(?string $tipo, ?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }
        $numero = trim($numero);
        $digitos = preg_replace('/\D+/', '', $numero) ?? '';

        return match ($tipo) {
            '36' => $digitos,
            '13' => strlen($digitos) === 9 ? substr($digitos, 0, 8).'-'.substr($digitos, 8) : $numero,
            default => $numero,
        };
    }

    /**
     * Problemas de una persona del evento, en lenguaje de quien factura. Lista vacía = válida.
     *
     * @return array<int, string>
     */
    public static function problemas(string $rol, ?string $nombre, ?string $tipo, ?string $numero): array
    {
        $problemas = [];
        $nombre = trim((string) $nombre);
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $problemas[] = "Falta el nombre de {$rol} (hasta 100 caracteres).";
        }
        if (! array_key_exists((string) $tipo, self::TIPOS)) {
            $problemas[] = "El tipo de documento de {$rol} debe ser uno de CAT-022: 36 NIT, 13 DUI, 37 Otro, 03 Pasaporte o 02 Carnet de residente.";

            return $problemas;
        }

        $numero = (string) self::normalizarNumero($tipo, $numero);
        $valido = match ($tipo) {
            '36' => preg_match('/^(\d{14}|\d{9})$/', $numero) === 1,
            '13' => preg_match('/^\d{8}-\d$/', $numero) === 1,
            default => $numero !== '' && mb_strlen($numero) <= 20,
        };
        if (! $valido) {
            $problemas[] = match ($tipo) {
                '36' => "El NIT de {$rol} debe tener 14 dígitos (o 9 si es el DUI homologado).",
                '13' => "El DUI de {$rol} debe tener 9 dígitos (00000000-0).",
                default => "Falta el número de documento de {$rol} (hasta 20 caracteres).",
            };
        }

        return $problemas;
    }
}
