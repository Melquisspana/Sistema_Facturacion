<?php

namespace Database\Factories\Asistencia;

use App\Models\Asistencia\AsistenciaEmpleado;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AsistenciaEmpleado>
 */
class AsistenciaEmpleadoFactory extends Factory
{
    protected $model = AsistenciaEmpleado::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // `codigo` queda NULL por defecto: es nullable y único, y llenarlo con
            // un valor al azar en cada empleado de prueba solo agrega colisiones
            // que no se parecen a ningún caso real.
            'codigo' => null,
            'nombres' => $this->faker->firstName().' '.$this->faker->firstName(),
            'apellidos' => $this->faker->lastName().' '.$this->faker->lastName(),
            'activo' => true,
            'fecha_ingreso' => null,
            'user_id' => null,
        ];
    }

    /** Alguien que ya no marca: su huella existe pero el empleado está de baja. */
    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }
}
