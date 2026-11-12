<?php

namespace App\Support\Dte;

use App\Models\Dte;
use App\Services\Dte\DteInvalidacionService;
use App\Services\Dte\DteTransmisionService;

/**
 * Una respuesta definitiva de Hacienda solo se guarda si se puede comprobar que es de ESTE
 * documento. Regla única para la emisión ({@see DteTransmisionService}) y la invalidación
 * ({@see DteInvalidacionService}); el sello, que además hace falta para aceptar, lo pide
 * quien llama.
 */
final class IdentidadRespuestaHacienda
{
    /**
     * Qué le falta a la respuesta para saber que es de este documento, o null si nada:
     *
     *   · el código de generación por el que se preguntó tiene que ser el de la fila;
     *   · si la respuesta trae un código, tiene que ser el del documento o uno de
     *     `$otrosCodigosAdmitidos` (en la invalidación, el del evento que se envió);
     *   · si se sabe de qué ambiente es la respuesta (no nulo), tiene que ser el del
     *     documento: lo que diga pruebas no vale para un documento de producción, ni al revés.
     *
     * @param  list<string>  $otrosCodigosAdmitidos
     */
    public static function falta(
        Dte $dte,
        string $codigoDeEstePedido,
        mixed $codigoDeLaRespuesta,
        mixed $ambienteDeLaRespuesta,
        array $otrosCodigosAdmitidos = [],
    ): ?string {
        $deLaFila = self::codigo($dte->codigo_generacion);

        if ($deLaFila === '' || self::codigo($codigoDeEstePedido) !== $deLaFila) {
            return 'el código de generación por el que se preguntó no es el del documento';
        }

        $admitidos = array_merge([$deLaFila], array_map(self::codigo(...), $otrosCodigosAdmitidos));
        if (filled($codigoDeLaRespuesta) && ! in_array(self::codigo($codigoDeLaRespuesta), $admitidos, true)) {
            return 'la respuesta es de otro código de generación';
        }

        if ($ambienteDeLaRespuesta !== null) {
            $ambiente = trim((string) $ambienteDeLaRespuesta);
            if ($ambiente !== $dte->ambiente?->value) {
                return 'la respuesta es del ambiente '.$ambiente.' y el documento es del ambiente '.($dte->ambiente?->value ?? '(sin ambiente)');
            }
        }

        return null;
    }

    private static function codigo(mixed $codigo): string
    {
        return strtoupper(trim((string) $codigo));
    }
}
