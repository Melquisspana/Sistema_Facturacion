<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * Se intentó actualizar o eliminar una fila del libro mayor.
 *
 * El mayor es append-only. Deshacer algo se registra con un movimiento de
 * compensación de tipo `reversion_*`, nunca borrando ni editando el original.
 */
class MovimientoInmutableException extends RuntimeException
{
    public static function actualizar(?int $id): self
    {
        return new self(
            "El movimiento de inventario #{$id} no puede actualizarse: el libro mayor es append-only. "
            .'Para deshacer su efecto, registra un movimiento de compensación (tipo reversion_*).'
        );
    }

    public static function eliminar(?int $id): self
    {
        return new self(
            "El movimiento de inventario #{$id} no puede eliminarse: borrar un hecho del mayor "
            .'descuadra el saldo y rompe la trazabilidad. Usa un movimiento de compensación.'
        );
    }
}
