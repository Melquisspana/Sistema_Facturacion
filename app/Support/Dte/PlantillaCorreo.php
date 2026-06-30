<?php

namespace App\Support\Dte;

use App\Models\Dte;

/**
 * Plantilla del correo del DTE: reemplaza las variables {{...}} con datos del
 * documento. Variables soportadas: cliente, documento, numero_control,
 * codigo_generacion, fecha, empresa, total.
 */
class PlantillaCorreo
{
    public const DEFAULT = <<<'TXT'
        Estimado cliente {{cliente}},

        Adjuntamos su {{documento}} emitido por {{empresa}}.

        Número de control: {{numero_control}}
        Código de generación: {{codigo_generacion}}
        Fecha: {{fecha}}
        Total: {{total}}

        Gracias por su preferencia.
        TXT;

    /** Renderiza la plantilla (o la default) con los datos del DTE. */
    public static function render(?string $plantilla, Dte $dte): string
    {
        $plantilla = ($plantilla !== null && trim($plantilla) !== '') ? $plantilla : self::DEFAULT;

        return strtr($plantilla, self::variables($dte));
    }

    /** @return array<string, string> */
    public static function variables(Dte $dte): array
    {
        return [
            '{{cliente}}' => (string) ($dte->cliente?->nombre ?? ''),
            '{{documento}}' => $dte->tipo_dte->label(),
            '{{numero_control}}' => (string) ($dte->numero_control ?? ''),
            '{{codigo_generacion}}' => (string) ($dte->codigo_generacion ?? ''),
            '{{fecha}}' => (string) optional($dte->fecha_emision)->format('d/m/Y'),
            '{{empresa}}' => 'Dulces La Negrita',
            '{{total}}' => '$'.number_format((float) $dte->total_pagar, 2),
        ];
    }
}
