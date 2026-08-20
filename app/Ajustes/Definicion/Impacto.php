<?php

namespace App\Ajustes\Definicion;

/**
 * Consecuencia de equivocarse al cambiar el ajuste. Es informativo/organizativo:
 * quien decide qué se le exige al usuario es {@see NivelConfirmacion}. Se guardan
 * separados a propósito — un ajuste de impacto alto puede seguir siendo N2, y
 * subir un N2 a N3 debe ser una decisión explícita, no un efecto colateral.
 */
enum Impacto: string
{
    case Bajo = 'bajo';
    case Medio = 'medio';
    case Alto = 'alto';
    case FiscalCritico = 'fiscal_critico';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bajo => 'Impacto bajo',
            self::Medio => 'Impacto medio',
            self::Alto => 'Impacto alto',
            self::FiscalCritico => 'Impacto fiscal crítico',
        };
    }
}
