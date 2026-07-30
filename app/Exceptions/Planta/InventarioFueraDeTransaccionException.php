<?php

namespace App\Exceptions\Planta;

use LogicException;

/**
 * Se intentó aplicar un movimiento de inventario sin una transacción abierta.
 *
 * Es LogicException y no RuntimeException a propósito: no es un dato malo del
 * usuario, es código mal escrito. Un movimiento inserta en el mayor Y actualiza
 * la proyección de saldo; sin transacción, un fallo entre ambas escrituras deja
 * el inventario descuadrado de forma permanente. No hay reintento que arregle
 * eso, así que la llamada se rechaza antes de tocar nada.
 */
class InventarioFueraDeTransaccionException extends LogicException
{
    public static function crear(): self
    {
        return new self(
            'PlantaInventarioService::aplicarMovimiento() exige una transacción abierta: '
            .'envuelve la llamada en DB::transaction(). Sin ella, el movimiento y el saldo '
            .'pueden quedar desincronizados de forma irrecuperable.'
        );
    }
}
