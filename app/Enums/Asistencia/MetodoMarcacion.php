<?php

namespace App\Enums\Asistencia;

/**
 * Con qué se identificó la persona en el lector. Una corrección manual no tiene método
 * (la columna queda en NULL): no la hizo nadie en el lector.
 */
enum MetodoMarcacion: string
{
    case Huella = 'huella';
    case Numero = 'numero';

    public function label(): string
    {
        return match ($this) {
            self::Huella => 'Con huella',
            self::Numero => 'Con número',
        };
    }
}
