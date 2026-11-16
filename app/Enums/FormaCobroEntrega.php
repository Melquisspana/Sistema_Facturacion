<?php

namespace App\Enums;

enum FormaCobroEntrega: string
{
    case Efectivo = 'efectivo';
    case Cheque = 'cheque';
    case Transferencia = 'transferencia';
    // El pedido web con ruta (16/11/2026): se pagó en la página y se entrega sin cobro. No se
    // elige: lo pone EntregasCcf::registrar() y no está en opciones().
    case YaPagado = 'ya_pagado';

    public function label(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Cheque => 'Cheque',
            self::Transferencia => 'Transferencia',
            self::YaPagado => 'Ya pagado',
        };
    }

    /** Las que se eligen al entregar y en la ficha del cliente: todas menos «Ya pagado». */
    public static function opciones(): array
    {
        $elegibles = array_filter(self::cases(), fn (self $forma) => $forma !== self::YaPagado);

        return array_column(array_map(fn ($forma) => ['valor' => $forma->value, 'label' => $forma->label()], $elegibles), 'label', 'valor');
    }

    public function columna(): ?string
    {
        return match ($this) {
            self::Efectivo => 'efectivo',
            self::Cheque => 'cheques',
            self::Transferencia => 'transferencias',
            self::YaPagado => null,
        };
    }
}
