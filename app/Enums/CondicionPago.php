<?php

namespace App\Enums;

/**
 * Condición de la operación (forma de pago).
 * Códigos según catálogo CAT-016 del Ministerio de Hacienda.
 */
enum CondicionPago: int
{
    case Contado = 1;
    case Credito = 2;
    case Otro = 3;

    public function label(): string
    {
        return match ($this) {
            self::Contado => 'Contado',
            self::Credito => 'Crédito',
            self::Otro => 'Otro',
        };
    }
}
