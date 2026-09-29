<?php

namespace App\Enums;

/**
 * Quién anotó el resultado de una entrega. La confirmación por albarán NO es un origen
 * guardado: se deriva al leer (ver la migración de `salida_ruta_entregas`).
 */
enum OrigenRegistroEntrega: string
{
    /** El vendedor, desde su celular (etapa 3). */
    case Vendedor = 'vendedor';
    /** La oficina, por el vendedor. */
    case Oficina = 'oficina';

    public function label(): string
    {
        return match ($this) {
            self::Vendedor => 'Vendedor',
            self::Oficina => 'Oficina',
        };
    }
}
