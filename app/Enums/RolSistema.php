<?php

namespace App\Enums;

/**
 * Roles base del sistema. Centraliza los nombres para evitar "strings mágicos"
 * en seeders, middleware y policies.
 *
 * Los permisos granulares por rol se definirán en la fase de seguridad/usuarios.
 */
enum RolSistema: string
{
    case Administrador = 'administrador';
    case Facturacion = 'facturacion';
    case Consulta = 'consulta';
    case Contador = 'contador';

    public function label(): string
    {
        return match ($this) {
            self::Administrador => 'Administrador',
            self::Facturacion => 'Facturación',
            self::Consulta => 'Consulta',
            self::Contador => 'Contador',
        };
    }

    /** @return array<int, string> Nombres de todos los roles. */
    public static function nombres(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
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
