<?php

namespace App\Enums;

/**
 * Por qué no se entregó un CCF. Los dos primeros son los de todos los días (el usuario,
 * 26/09/2026): se terminó tarde y no se llegó antes de las 5, o la sala ya no recibía a esa
 * hora. `Otro` exige nota.
 */
enum MotivoNoEntrega: string
{
    case SinTiempo = 'sin_tiempo';
    case FueraDeHorario = 'fuera_de_horario';
    case SalaCerrada = 'sala_cerrada';
    case NoAceptado = 'no_aceptado';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::SinTiempo => 'No alcanzó el tiempo',
            self::FueraDeHorario => 'La sala ya no recibía a esa hora',
            self::SalaCerrada => 'Sala cerrada',
            self::NoAceptado => 'La sala no lo aceptó',
            self::Otro => 'Otro',
        };
    }

    public function exigeNota(): bool
    {
        return $this === self::Otro;
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
