<?php

namespace App\Enums;

/**
 * De dónde sale el porcentaje de descuento global de una Nota de Crédito.
 *
 * Existe porque la regla NO es la misma para todas las modalidades ni para todos los
 * clientes. El caso que la motivó: en los albaranes de crédito de Calleja, la AVERÍA
 * (AC02) sí lleva el descuento comercial —$2.89 bruto − $0.14 = $2.75 gravado— y la
 * DEVOLUCIÓN (AC04) NO lo lleva —6 × $1.04 = $6.24 gravado, «Descuentos Generales:
 * Monetario 0.00 / Porcentaje 0» impreso en el propio documento— aunque el CCF
 * relacionado tenga su 5 % habitual.
 *
 * Antes de esto había una sola condición para las dos modalidades, así que una u otra
 * tenía que salir mal. El origen se declara por cliente y por modalidad; sin perfil
 * declarado se conserva el comportamiento histórico exacto (ver
 * {@see \App\Services\Dte\DteBorradorService::porcentajeDescuentoNotaCredito()}).
 */
enum OrigenDescuentoNc: string
{
    /** Hereda el descuento_porcentaje_aplicado del CCF relacionado. Es lo histórico. */
    case Ccf = 'ccf';

    /** Sin descuento global (0 %), aunque el CCF relacionado sí tenga. */
    case Ninguno = 'ninguno';

    /** Tasa fija propia de la modalidad, independiente del CCF (columna descuento_tasa). */
    case TasaPropia = 'tasa_propia';

    public function label(): string
    {
        return match ($this) {
            self::Ccf => 'Heredado del CCF relacionado',
            self::Ninguno => 'Sin descuento',
            self::TasaPropia => 'Tasa propia de la modalidad',
        };
    }

    /** ¿Necesita que descuento_tasa venga con valor? */
    public function requiereTasa(): bool
    {
        return $this === self::TasaPropia;
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
