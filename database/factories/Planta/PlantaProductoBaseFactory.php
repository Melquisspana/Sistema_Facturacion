<?php

namespace Database\Factories\Planta;

use App\Models\Planta\PlantaProductoBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaProductoBase>
 */
class PlantaProductoBaseFactory extends Factory
{
    protected $model = PlantaProductoBase::class;

    public function definition(): array
    {
        return [
            'codigo' => 'PB'.$this->faker->unique()->numberBetween(1000, 9999),
            'nombre' => $this->faker->words(2, true),
            'descripcion' => null,
            'activo' => true,
        ];
    }
}
