<?php

namespace App\Enums\Planta;

/**
 * Mercado al que va dirigida una configuración de empaque.
 *
 * Vive en la configuración de empaque y NO en el producto base ni en la
 * presentación: el mismo dulce, en el mismo formato, puede empacarse con bolsa
 * y viñeta distintas según el mercado.
 *
 * No tiene relación con el tipo de DTE ni con la exportación fiscal: aquí
 * «exportación» solo describe el destino comercial del empaque.
 */
enum MercadoPlanta: string
{
    case Nacional = 'nacional';
    case Exportacion = 'exportacion';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Nacional => 'Nacional',
            self::Exportacion => 'Exportación',
            self::Otro => 'Otro',
        };
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Nacional => 'blue',
            self::Exportacion => 'indigo',
            self::Otro => 'gray',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }
}
