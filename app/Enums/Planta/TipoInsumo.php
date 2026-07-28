<?php

namespace App\Enums\Planta;

/**
 * Qué clase de insumo se almacena en Planta.
 *
 * Determina cómo se comporta en el inventario, no su unidad: las bolsas y
 * viñetas se cuentan por unidades enteras (`permite_fraccion = false` en el
 * catálogo), mientras que las materias primas admiten fracción de libra.
 *
 * NO es el catálogo fiscal de productos: Planta está aislada de Facturación y
 * no referencia `productos` ni `unidades_medida` (CAT-014 del MH).
 */
enum TipoInsumo: string
{
    case MateriaPrima = 'materia_prima';
    case Bolsa = 'bolsa';
    case Vinieta = 'vinieta';
    case Empaque = 'empaque';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::MateriaPrima => 'Materia prima',
            self::Bolsa => 'Bolsa',
            self::Vinieta => 'Viñeta',
            self::Empaque => 'Empaque',
            self::Otro => 'Otro',
        };
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::MateriaPrima => 'green',
            self::Bolsa => 'blue',
            self::Vinieta => 'indigo',
            self::Empaque => 'amber',
            self::Otro => 'gray',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
