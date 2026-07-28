<?php

namespace Database\Factories\Planta;

use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaProductoBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaPresentacion>
 */
class PlantaPresentacionFactory extends Factory
{
    protected $model = PlantaPresentacion::class;

    public function definition(): array
    {
        return [
            'planta_producto_base_id' => PlantaProductoBase::factory(),
            'codigo' => 'PR'.$this->faker->unique()->numberBetween(1000, 9999),
            'nombre' => $this->faker->unique()->words(2, true),
            'contenido' => null,
            'unidad_contenido' => null,
            'unidades_por_bulto' => null,
            'activo' => true,
        ];
    }
}
