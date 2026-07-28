<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\TipoUbicacion;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaUbicacion>
 */
class PlantaUbicacionFactory extends Factory
{
    protected $model = PlantaUbicacion::class;

    public function definition(): array
    {
        return [
            'codigo' => 'UB'.$this->faker->unique()->numberBetween(1000, 9999),
            'nombre' => $this->faker->words(2, true),
            'tipo' => TipoUbicacion::Fisica->value,
            'es_sistema' => false,
            'permite_operacion_manual' => true,
            'activo' => true,
            'orden' => 0,
        ];
    }

    /** La ubicación de sistema «en tránsito»: no admite operación manual. */
    public function transito(): static
    {
        return $this->state(fn () => [
            'codigo' => 'TRANSITO',
            'nombre' => 'En tránsito',
            'tipo' => TipoUbicacion::Transito->value,
            'es_sistema' => true,
            'permite_operacion_manual' => false,
        ]);
    }
}
