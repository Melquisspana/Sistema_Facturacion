<?php

namespace App\Enums;

enum FormaCobroEntrega: string
{
    case Efectivo = 'efectivo';
    case Cheque = 'cheque';
    case Transferencia = 'transferencia';

    public function label(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Cheque => 'Cheque',
            self::Transferencia => 'Transferencia',
        };
    }

    public static function opciones(): array
    {
        return array_column(array_map(fn ($forma) => ['valor' => $forma->value, 'label' => $forma->label()], self::cases()), 'label', 'valor');
    }

    public function columna(): string
    {
        return match ($this) {
            self::Efectivo => 'efectivo',
            self::Cheque => 'cheques',
            self::Transferencia => 'transferencias',
        };
    }
}
