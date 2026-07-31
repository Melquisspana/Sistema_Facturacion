<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El cambio de disponibilidad no está en condiciones de hacer lo que se le pide.
 *
 * Cubre lo que ningún Form Request puede garantizar porque depende del estado de
 * la base EN EL MOMENTO de la operación: el estado del documento, que la
 * ubicación siga admitiendo operación manual, que el lote siga siendo del
 * insumo, y sobre todo que HAYA saldo retenido suficiente en el bucket exacto.
 * Se comprueba dentro de la transacción y con la fila bloqueada.
 */
class CambioDisponibilidadInvalidoException extends RuntimeException
{
    public static function estadoNoPermite(string $estado, string $accion): self
    {
        return new self("Un cambio de disponibilidad en estado «{$estado}» no admite {$accion}.");
    }

    public static function cantidadNoPositiva(string $cantidad): self
    {
        return new self("La cantidad debe ser mayor que cero (recibida: {$cantidad}).");
    }

    /**
     * El origen SIEMPRE es retenido. Liberar algo que ya está disponible no
     * cambia nada, y retener lo disponible es una operación distinta que Fase 2
     * no implementa.
     */
    public static function origenNoRetenido(string $origen): self
    {
        return new self(
            "Este documento solo mueve saldo RETENIDO, y el origen indicado es «{$origen}». "
            .'Retener saldo disponible o devolver lo rechazado son operaciones distintas que '
            .'todavía no existen.'
        );
    }

    public static function destinoNoPermitido(string $destino): self
    {
        return new self(
            "«{$destino}» no es un destino válido: el saldo retenido solo puede liberarse "
            .'(disponible) o rechazarse (rechazado).'
        );
    }

    public static function ubicacionInactiva(string $nombre): self
    {
        return new self("La ubicación «{$nombre}» está inactiva: no se puede operar sobre su saldo.");
    }

    public static function ubicacionNoAdmiteOperacion(string $nombre): self
    {
        return new self(
            "La ubicación «{$nombre}» no admite operación manual: su saldo solo lo mueven los "
            .'traslados. El saldo en tránsito no cambia de disponibilidad.'
        );
    }

    public static function insumoInactivo(string $nombre): self
    {
        return new self("El insumo «{$nombre}» está inactivo.");
    }

    public static function loteAjeno(int $loteId, string $insumo): self
    {
        return new self("El lote #{$loteId} no pertenece al insumo «{$insumo}».");
    }

    public static function saldoRetenidoInsuficiente(
        string $descripcionBucket,
        string $solicitado,
        string $disponible,
    ): self {
        return new self(sprintf(
            'No hay tanto saldo retenido: se piden %s en %s y solo hay %s. Este documento no busca '
            .'saldo en otra ubicación ni en otro lote.',
            $solicitado,
            $descripcionBucket,
            $disponible,
        ));
    }

    public static function motivoRequerido(): self
    {
        return new self(
            'Cambiar la disponibilidad exige un motivo: dentro de un mes será la única forma de '
            .'saber por qué ese saldo dejó de ser utilizable, o volvió a serlo.'
        );
    }
}
