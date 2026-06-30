<?php

namespace App\Enums;

/**
 * Tipo de documento de identificación del receptor.
 * Códigos según catálogo CAT-022 del Ministerio de Hacienda.
 */
enum TipoDocumentoCliente: string
{
    case Nit = '36';            // NIT
    case Dui = '13';            // DUI
    case CarnetResidente = '02'; // Carnet de residente
    case Pasaporte = '03';      // Pasaporte
    case Otro = '37';           // Otro

    public function label(): string
    {
        return match ($this) {
            self::Nit => 'NIT',
            self::Dui => 'DUI',
            self::CarnetResidente => 'Carnet de Residente',
            self::Pasaporte => 'Pasaporte',
            self::Otro => 'Otro',
        };
    }

    /** @return array<string, string> [valor => label] para selects. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
