<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El traslado no está en condiciones de hacer lo que se le pide.
 *
 * Cubre lo que ningún Form Request puede garantizar porque depende del estado de
 * la base EN EL MOMENTO de la operación: el estado del documento, que las
 * ubicaciones sigan siendo operables, que el lote siga siendo del insumo, y
 * sobre todo que HAYA saldo disponible suficiente en el bucket exacto de origen.
 */
class TrasladoInvalidoException extends RuntimeException
{
    public static function estadoNoPermite(string $estado, string $accion): self
    {
        return new self("Un traslado en estado «{$estado}» no admite {$accion}.");
    }

    public static function sinDetalles(int $numero): self
    {
        return new self("El traslado #{$numero} no tiene líneas: no hay nada que enviar.");
    }

    public static function mismaUbicacion(string $nombre): self
    {
        return new self(
            "El origen y el destino son la misma ubicación («{$nombre}»). Un traslado que no mueve "
            .'nada de sitio no es un traslado.'
        );
    }

    public static function ubicacionInactiva(string $nombre, string $papel): self
    {
        return new self("La ubicación de {$papel} «{$nombre}» está inactiva.");
    }

    public static function ubicacionNoOperable(string $nombre, string $papel): self
    {
        return new self(
            "La ubicación de {$papel} «{$nombre}» no admite operación manual. El tránsito no puede "
            .'ser origen ni destino de un traslado: es el sitio por donde se pasa, no de donde se '
            .'sale o a donde se llega.'
        );
    }

    public static function cantidadNoPositiva(string $cantidad): self
    {
        return new self("La cantidad debe ser mayor que cero (recibida: {$cantidad}).");
    }

    public static function insumoInactivo(string $nombre): self
    {
        return new self("El insumo «{$nombre}» está inactivo: no puede trasladarse.");
    }

    public static function loteAjeno(int $loteId, string $insumo): self
    {
        return new self("El lote #{$loteId} no pertenece al insumo «{$insumo}».");
    }

    public static function loteInactivo(string $codigo): self
    {
        return new self("El lote «{$codigo}» está inactivo: no puede trasladarse.");
    }

    public static function saldoInsuficienteEnOrigen(
        string $descripcionBucket,
        string $solicitado,
        string $disponible,
    ): self {
        return new self(sprintf(
            'No hay tanto saldo DISPONIBLE para enviar: se piden %s de %s y solo hay %s. Un traslado '
            .'solo mueve saldo disponible; el retenido y el rechazado no viajan.',
            $solicitado,
            $descripcionBucket,
            $disponible,
        ));
    }

    public static function motivoRequerido(): self
    {
        return new self(
            'Reversar un traslado exige un motivo: dentro de un mes será la única forma de saber por '
            .'qué se deshizo un movimiento de mercancía que ya se había registrado.'
        );
    }

    // --- Ubicación de tránsito ---

    /**
     * El tránsito NO se crea sobre la marcha. Crearlo dentro de una operación
     * significaría inventar una ubicación de sistema en mitad de un traslado, sin
     * que nadie lo haya decidido y sin que quede claro cuál es «la» de tránsito
     * si mañana aparece otra.
     */
    public static function transitoAusente(): self
    {
        return new self(
            'No existe la ubicación de TRÁNSITO del sistema. Un traslado necesita un sitio donde '
            .'esperar la mercancía entre la salida y la llegada. Debe existir exactamente una '
            .'ubicación de tipo `transito`, de sistema, activa y sin operación manual. No se crea '
            .'automáticamente: créala desde el catálogo de ubicaciones o con su seeder.'
        );
    }

    public static function transitoAmbiguo(int $cuantas): self
    {
        return new self(
            "Hay {$cuantas} ubicaciones de tránsito que cumplen los requisitos, y debe haber "
            .'exactamente una. Con varias, el saldo en viaje se repartiría entre ellas y «dónde está '
            .'lo que salió» dejaría de tener una respuesta.'
        );
    }
}
