<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El traslado ya no puede deshacerse porque su mercancía dejó de estar donde la
 * dejó.
 *
 * Reversar un ENVIADO retira del tránsito de ese viaje y devuelve al origen.
 * Reversar un RECIBIDO compensa: retira del destino y devuelve al origen, sin
 * recrear tránsito —no hubo un segundo viaje físico, hubo una corrección
 * contable, y fingir lo contrario dejaría en el mayor un recorrido que nadie
 * hizo—.
 *
 * En ambos casos el saldo tiene que seguir EXACTAMENTE en el bucket donde quedó.
 * Si se consumió, se trasladó otra vez o cambió de disponibilidad, retirarlo de
 * donde ya no está restaría de saldo que llegó por otra vía. La operación falla
 * entera y el mensaje dice qué bucket lo impide; no se busca en otra ubicación
 * ni en otro lote.
 */
class ReversionTrasladoImposibleException extends RuntimeException
{
    public static function saldoInsuficiente(
        string $descripcionBucket,
        string $requerido,
        string $disponible,
        string $desde,
    ): self {
        return new self(sprintf(
            'No se puede reversar: habría que retirar %s de %s (%s) y ahí solo quedan %s. Ese saldo '
            .'se consumió, se trasladó o cambió de disponibilidad. La reversión no lo busca en otro '
            .'bucket ni mueve inventario para compensarlo.',
            $requerido,
            $descripcionBucket,
            $desde,
            $disponible,
        ));
    }

    public static function yaReversado(int $numero): self
    {
        return new self("El traslado #{$numero} ya fue reversado: no se reversa dos veces.");
    }

    public static function esUnaReversion(int $numero): self
    {
        return new self("El documento #{$numero} es una reversión: no se reversa una reversión.");
    }

    public static function movimientoOriginalAusente(int $detalleId, string $lado): self
    {
        return new self(
            "No se encontró el movimiento de {$lado} de la línea #{$detalleId}. La reversión exige "
            .'apuntar a lo que compensa, y sin el original no hay nada que compensar.'
        );
    }
}
