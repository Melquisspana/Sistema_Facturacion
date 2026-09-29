<?php

namespace App\Enums;

/** Qué pasó con un CCF en una salida. Sin resultado (NULL) = todavía sin registrar. */
enum ResultadoEntrega: string
{
    case Entregado = 'entregado';
    case NoEntregado = 'no_entregado';

    public function label(): string
    {
        return match ($this) {
            self::Entregado => 'Entregado',
            self::NoEntregado => 'No entregado',
        };
    }
}
