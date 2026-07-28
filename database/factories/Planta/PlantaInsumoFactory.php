<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaInsumo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaInsumo>
 */
class PlantaInsumoFactory extends Factory
{
    protected $model = PlantaInsumo::class;

    public function definition(): array
    {
        return [
            'codigo' => 'INS'.$this->faker->unique()->numberBetween(1000, 9999),
            'nombre' => $this->faker->words(2, true),
            'tipo' => TipoInsumo::MateriaPrima->value,
            'unidad_base' => UnidadBase::Libra->value,
            'controla_lotes' => true,
            'permite_fraccion' => true,
            'factor_conversion_sugerido' => null,
            'unidad_recepcion_sugerida' => null,
            'contenido_sugerido' => null,
            'stock_minimo' => null,
            'activo' => true,
            'observaciones' => null,
        ];
    }

    /** Bolsa: se cuenta por unidades enteras y no controla lotes. */
    public function bolsa(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoInsumo::Bolsa->value,
            'unidad_base' => UnidadBase::Unidad->value,
            'controla_lotes' => false,
            'permite_fraccion' => false,
        ]);
    }

    /** Viñeta: igual que la bolsa en comportamiento de inventario. */
    public function vinieta(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoInsumo::Vinieta->value,
            'unidad_base' => UnidadBase::Unidad->value,
            'controla_lotes' => false,
            'permite_fraccion' => false,
        ]);
    }
}
