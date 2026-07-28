<?php

namespace Database\Factories\Planta;

use App\Models\Planta\PlantaProveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaProveedor>
 */
class PlantaProveedorFactory extends Factory
{
    protected $model = PlantaProveedor::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->company(),
            'nombre_comercial' => $this->faker->optional()->company(),
            'telefono' => $this->faker->optional()->numerify('####-####'),
            'correo' => $this->faker->optional()->safeEmail(),
            'contacto' => $this->faker->optional()->name(),
            'direccion' => $this->faker->optional()->address(),
            // NIT y NRC son texto libre y sin unique: se dejan vacíos por defecto.
            'nit' => null,
            'nrc' => null,
            'observaciones' => null,
            'activo' => true,
        ];
    }
}
