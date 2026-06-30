<?php

namespace App\Enums;

/**
 * Clasificación tributaria del producto frente al IVA.
 * Determina cómo se trata la línea en el documento (gravada/exenta/no sujeta).
 */
enum TipoImpuesto: string
{
    case Gravado = 'gravado';
    case Exento = 'exento';
    case NoSujeto = 'no_sujeto';

    public function label(): string
    {
        return match ($this) {
            self::Gravado => 'Gravado',
            self::Exento => 'Exento',
            self::NoSujeto => 'No sujeto',
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
