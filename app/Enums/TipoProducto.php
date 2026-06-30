<?php

namespace App\Enums;

/**
 * Tipo de ítem en una línea de documento.
 * Códigos según catálogo CAT-011 del Ministerio de Hacienda.
 */
enum TipoProducto: string
{
    case Bien = '1';     // Producto/bien
    case Servicio = '2'; // Servicio
    case Ambos = '3';    // Bien y servicio (mixto)
    case Otros = '4';    // Los demás (otros tributos por ítem)

    public function label(): string
    {
        return match ($this) {
            self::Bien => 'Bien',
            self::Servicio => 'Servicio',
            self::Ambos => 'Ambos',
            self::Otros => 'Otros',
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
