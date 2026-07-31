<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El ajuste confirmado ya no puede deshacerse porque el saldo dejó de estar
 * donde lo dejó.
 *
 * Reversar un ajuste es aplicar su efecto al revés sobre el MISMO bucket:
 *
 *   - lo que SUMÓ hay que restarlo. Falla si ese saldo ya se consumió, se
 *     trasladó o cambió de disponibilidad: retirarlo de donde ya no está
 *     restaría de saldo que llegó por otra vía.
 *   - lo que RESTÓ hay que volver a sumarlo, y eso no puede fallar por saldo
 *     —sumar siempre cabe—, pero sí exige que el bucket siga siendo un destino
 *     válido.
 *
 * Deliberadamente NO se busca el saldo en otra ubicación ni en otro lote, y no
 * existe la reversión parcial: se deshace entero o no se deshace.
 */
class ReversionAjusteImposibleException extends RuntimeException
{
    public static function saldoInsuficiente(
        string $descripcionBucket,
        string $requerido,
        string $disponible,
    ): self {
        return new self(sprintf(
            'No se puede reversar: habría que retirar %s de %s y ahí solo quedan %s. Ese saldo se '
            .'consumió, se trasladó o cambió de disponibilidad. La reversión no lo busca en otro '
            .'bucket ni mueve inventario para compensarlo.',
            $requerido,
            $descripcionBucket,
            $disponible,
        ));
    }

    public static function yaReversado(int $numero): self
    {
        return new self("El ajuste #{$numero} ya fue reversado: no se reversa dos veces.");
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
