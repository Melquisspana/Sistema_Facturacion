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

    /**
     * Código del tipo de persona según el esquema del MH: 1 = natural, 2 = jurídica.
     * El valor del enum es un string ('natural'/'juridica'), NO un número; castearlo
     * con (int) daría 0, por eso el mapeo es explícito.
     */
    public function codigoMh(): int
    {
        return match ($this) {
            self::Natural => 1,
            self::Juridica => 2,
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
