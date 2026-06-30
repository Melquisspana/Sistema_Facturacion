<?php

namespace App\Enums;

/**
 * Tipo de persona del receptor. Relevante sobre todo para exportación (FEX).
 */
enum TipoPersona: string
{
    case Natural = 'natural';
    case Juridica = 'juridica';

    public function label(): string
    {
        return match ($this) {
            self::Natural => 'Persona natural',
            self::Juridica => 'Persona jurídica',
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
