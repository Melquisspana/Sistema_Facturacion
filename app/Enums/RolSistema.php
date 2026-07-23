<?php

namespace App\Enums;

/**
 * Roles base del sistema. Centraliza los nombres para evitar "strings mágicos"
 * en seeders, middleware y policies. Los permisos concretos de cada rol viven en
 * {@see PermisoSistema} (fuente única de verdad); aquí solo se nombran los roles.
 */
enum RolSistema: string
{
    case Administrador = 'administrador';
    case Jefatura = 'jefatura';
    case Facturacion = 'facturacion';
    case Contabilidad = 'contabilidad';

    public function label(): string
    {
        return match ($this) {
            self::Administrador => 'Administrador',
            self::Jefatura => 'Jefatura',
            self::Facturacion => 'Facturación',
            self::Contabilidad => 'Contabilidad',
        };
    }

    /** @return array<int, string> Permisos que corresponden a este rol. */
    public function permisos(): array
    {
        return PermisoSistema::paraRol($this);
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
