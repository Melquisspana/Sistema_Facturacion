<?php

namespace App\Enums;

/**
 * Por qué no se entregó un CCF. Los que se eligen (Melqui, 10/11/2026): «No alcanzó el
 * tiempo», «Estaba cerrada», «No había quien recibiera», «No quiso» y «Otro», que exige
 * nota. `FueraDeHorario` ya no se ofrece: queda para leer las entregas de antes.
 */
enum MotivoNoEntrega: string
{
    case SinTiempo = 'sin_tiempo';
    case SalaCerrada = 'sala_cerrada';
    case SinQuienReciba = 'sin_quien_reciba';
    case NoAceptado = 'no_aceptado';
    case Otro = 'otro';
    case FueraDeHorario = 'fuera_de_horario';

    public function label(): string
    {
        return match ($this) {
            self::SinTiempo => 'No alcanzó el tiempo',
            self::SalaCerrada => 'Estaba cerrada',
            self::SinQuienReciba => 'No había quien recibiera',
            self::NoAceptado => 'No quiso',
            self::Otro => 'Otro',
            self::FueraDeHorario => 'La sala ya no recibía a esa hora',
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
            if ($caso !== self::FueraDeHorario) {
                $opciones[$caso->value] = $caso->label();
            }
        }

        return $opciones;
    }
}
