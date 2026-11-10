<?php

namespace App\Support\Dte;

use App\Models\Cliente;
use App\Models\ClienteSucursal;

/**
 * Regla ÚNICA de la condición de operación de un documento nuevo: no se pide a mano, se
 * toma de la sala, luego del cliente y, si ninguno la tiene, del valor por defecto para
 * contribuyentes. La usan el alta de cada DTE y «Facturar desde el pedido».
 */
final class CondicionOperacion
{
    /** Condición de operación a aplicar: sala → cliente → default contribuyente. */
    public static function para(?Cliente $cliente, ?ClienteSucursal $sucursal): int
    {
        return (int) ($sucursal?->condicion_operacion_default
            ?? $cliente?->condicion_operacion_default
            ?? config('dte.condicion_operacion_default_contribuyente', 2));
    }
}
