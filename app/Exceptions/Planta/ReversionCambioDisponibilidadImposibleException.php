<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El cambio de disponibilidad ya no puede deshacerse porque el saldo dejó de
 * estar donde lo dejó.
 *
 * Reversar significa devolver EXACTAMENTE la misma cantidad al estado de origen,
 * desde el MISMO bucket de destino. Si ese saldo ya se trasladó, se consumió o
 * volvió a cambiar de disponibilidad, retirarlo de donde ya no está produciría
 * un negativo o —peor— restaría de saldo que llegó por otra vía.
 *
 * Deliberadamente NO se busca el saldo en otra ubicación ni en otro lote: eso
 * sería inventar un movimiento que nadie hizo. La operación falla entera y el
 * mensaje dice qué bucket lo impide.
 */
class ReversionCambioDisponibilidadImposibleException extends RuntimeException
{
    public static function saldoDestinoInsuficiente(
        string $descripcionBucket,
        string $requerido,
        string $disponible,
    ): self {
        return new self(sprintf(
            'No se puede reversar: habría que devolver %s desde %s, y ahí solo quedan %s. Ese saldo '
            .'se trasladó, se consumió o volvió a cambiar de disponibilidad. La reversión no lo '
            .'busca en otro bucket ni mueve inventario para compensarlo.',
            $requerido,
            $descripcionBucket,
            $disponible,
        ));
    }

    public static function yaReversado(int $numero): self
    {
        return new self("El cambio de disponibilidad #{$numero} ya fue reversado: no se reversa dos veces.");
    }

    public static function esUnaReversion(int $numero): self
    {
        return new self("El documento #{$numero} es una reversión: no se reversa una reversión.");
    }

    public static function movimientoOriginalAusente(int $numero, string $lado): self
    {
        return new self(
            "No se encontró el movimiento de {$lado} del cambio #{$numero}. La reversión exige "
            .'apuntar a lo que compensa, y sin el original no hay nada que compensar.'
        );
    }
}
