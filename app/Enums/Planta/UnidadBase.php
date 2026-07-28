<?php

namespace App\Enums\Planta;

/**
 * Unidad en la que Planta lleva TODOS sus saldos y movimientos.
 *
 * Es deliberadamente corta: la recepción admite cualquier unidad de compra
 * (saco, caja, kg, paquete) y la convierte UNA sola vez a esta unidad base;
 * a partir de ahí el inventario nunca se reconvierte.
 *
 * No se reutiliza la tabla `unidades_medida` porque es el catálogo CAT-014 del
 * Ministerio de Hacienda y arrastraría a Planta al dominio fiscal.
 */
enum UnidadBase: string
{
    case Libra = 'libra';
    case Unidad = 'unidad';

    public function label(): string
    {
        return match ($this) {
            self::Libra => 'Libra',
            self::Unidad => 'Unidad',
        };
    }

    /** Abreviatura para listados y reportes. */
    public function abreviatura(): string
    {
        return match ($this) {
            self::Libra => 'lb',
            self::Unidad => 'u',
        };
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Libra => 'green',
            self::Unidad => 'blue',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $u) => ['value' => $u->value, 'label' => $u->label()], self::cases());
    }
}
