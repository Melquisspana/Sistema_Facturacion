<?php

namespace App\Support\Exportaciones;

/**
 * Empaques de uso diario. «12×12» son 12 cajitas de 12 unidades embolsadas: 144
 * unidades por caja. El texto de `unidad` es el que se imprime en la lista de
 * empaque; la etiqueta corta es la que se muestra en pantalla.
 */
final class EmpaqueExportacion
{
    /** @var array<string, array{unidades:int, unidad:string, etiqueta:string}> */
    public const PREDEFINIDOS = [
        '12x12' => ['unidades' => 144, 'unidad' => 'Bolsa de polipropileno 12x12', 'etiqueta' => 'Caja 12×12'],
        '12x18' => ['unidades' => 216, 'unidad' => 'Bolsa de polipropileno 12x18', 'etiqueta' => 'Caja 12×18'],
        '24x12' => ['unidades' => 288, 'unidad' => 'Bolsa de polipropileno 24x12', 'etiqueta' => 'Caja 24×12'],
    ];

    /** Clave del predefinido que coincide con la presentación, o null si es «otro». */
    public static function clave(?string $unidad, int $unidades): ?string
    {
        foreach (self::PREDEFINIDOS as $clave => $empaque) {
            if ($empaque['unidades'] === $unidades && strcasecmp(trim((string) $unidad), $empaque['unidad']) === 0) {
                return $clave;
            }
        }

        return null;
    }

    /** «Caja 12×12 · 144 u», o «Empaque plástico 36x1 · 36 u». */
    public static function etiqueta(?string $unidad, int $unidades): string
    {
        $clave = self::clave($unidad, $unidades);

        if ($clave !== null) {
            $nombre = self::PREDEFINIDOS[$clave]['etiqueta'];
        } elseif (preg_match('/polipropileno\s+(\d+)\s*x\s*(\d+)/i', (string) $unidad, $m) === 1) {
            // Otra bolsa AxB (p. ej. la 12x10 de Diamond) se lee igual que las de siempre.
            $nombre = "Caja {$m[1]}×{$m[2]}";
        } else {
            $nombre = trim((string) $unidad) ?: 'Caja';
        }

        return $nombre.' · '.number_format($unidades).' u';
    }
}
