<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * La recepción confirmada ya no puede deshacerse porque su mercancía dejó de
 * estar donde entró.
 *
 * Reversar significa retirar EXACTAMENTE lo que entró, del MISMO bucket. Si ese
 * saldo ya se trasladó, se consumió o cambió de disponibilidad, retirarlo de
 * donde ya no está produciría un negativo o —peor— restaría de saldo que
 * pertenece a otra entrada.
 *
 * Deliberadamente NO se busca el saldo en otra ubicación ni se mueve inventario
 * de vuelta para «hacer sitio»: eso sería inventar un movimiento físico que
 * nadie hizo. La operación falla entera y el mensaje dice qué lote y qué bucket
 * lo impiden, para que la persona decida qué hacer con conocimiento.
 */
class ReversionRecepcionImposibleException extends RuntimeException
{
    public static function saldoInsuficiente(
        string $descripcionBucket,
        string $codigoLote,
        string $requerido,
        string $disponible,
    ): self {
        return new self(sprintf(
            'No se puede reversar: el lote «%s» necesita devolver %s en %s, y ahí solo quedan %s. '
            .'Ese saldo se trasladó, se consumió o cambió de disponibilidad. La reversión no lo '
            .'busca en otra ubicación ni mueve inventario para compensarlo: corrígelo con el '
            .'documento que corresponda.',
            $codigoLote,
            $requerido,
            $descripcionBucket,
            $disponible,
        ));
    }

    public static function yaReversada(int $numero): self
    {
        return new self("La recepción #{$numero} ya fue reversada: no puede reversarse dos veces.");
    }

    public static function esUnaReversion(int $numero): self
    {
        return new self("El documento #{$numero} es una reversión: no se reversa una reversión.");
    }

    public static function movimientoOriginalAusente(int $detalleId): self
    {
        return new self(
            "No se encontró el movimiento original de la línea #{$detalleId}. La reversión exige "
            .'apuntar a lo que compensa, y sin el original no hay nada que compensar.'
        );
    }
}
