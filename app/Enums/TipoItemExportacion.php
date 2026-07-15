<?php

namespace App\Enums;

/**
 * Tipo de ítem de la Factura de exportación (FEX, tipo 11). No viene de
 * catalogos_mh: el MH lo documenta como un valor fijo de 2 opciones, igual que
 * TipoPersona.
 */
enum TipoItemExportacion: int
{
    case Bienes = 1;
    case Servicios = 2;

    public function label(): string
    {
        return match ($this) {
            self::Bienes => 'Bienes',
            self::Servicios => 'Servicios',
        };
    }
}
