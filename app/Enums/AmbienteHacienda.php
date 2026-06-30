<?php

namespace App\Enums;

/**
 * Ambiente de destino para los servicios del Ministerio de Hacienda.
 * Códigos según catálogo CAT-001.
 */
enum AmbienteHacienda: string
{
    case Pruebas = '00';
    case Produccion = '01';

    public function label(): string
    {
        return match ($this) {
            self::Pruebas => 'Pruebas',
            self::Produccion => 'Producción',
        };
    }

    public function esProduccion(): bool
    {
        return $this === self::Produccion;
    }
}
