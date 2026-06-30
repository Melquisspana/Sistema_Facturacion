<?php

namespace App\Enums;

/**
 * Tipo de establecimiento (CAT-009 del Ministerio de Hacienda).
 */
enum TipoEstablecimiento: string
{
    case Sucursal = '01';   // Sucursal / Agencia
    case CasaMatriz = '02'; // Casa matriz
    case Bodega = '04';     // Bodega
    case Predio = '07';     // Predio o patio
    case Otro = '20';       // Otro

    public function label(): string
    {
        return match ($this) {
            self::Sucursal => 'Sucursal / Agencia',
            self::CasaMatriz => 'Casa Matriz',
            self::Bodega => 'Bodega',
            self::Predio => 'Predio o Patio',
            self::Otro => 'Otro',
        };
    }

    /** @return array<string, string> [codigo => label] para selects. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
