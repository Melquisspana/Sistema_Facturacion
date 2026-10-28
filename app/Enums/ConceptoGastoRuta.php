<?php

namespace App\Enums;

enum ConceptoGastoRuta: string
{
    case Parqueo = 'parqueo';
    case Gasolina = 'gasolina';
    case Viaticos = 'viaticos';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Parqueo => 'Parqueo',
            self::Gasolina => 'Gasolina',
            self::Viaticos => 'Viáticos',
            self::Otro => 'Otro',
        };
    }

    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
