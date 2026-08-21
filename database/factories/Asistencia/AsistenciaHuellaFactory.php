<?php

namespace Database\Factories\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Una ASIGNACIÓN de ranura. Ojo con el nombre: no es «una huella», es «esta
 * ranura fue de esta persona durante este período».
 *
 * @extends Factory<AsistenciaHuella>
 */
class AsistenciaHuellaFactory extends Factory
{
    protected $model = AsistenciaHuella::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'asistencia_empleado_id' => AsistenciaEmpleado::factory(),
            'asistencia_dispositivo_id' => AsistenciaDispositivo::factory(),
            'fingerprint_id' => $this->faker->unique()->numberBetween(1, 160),
            'activo' => true,
            'liberada_at' => null,
        ];
    }

    /**
     * Una asignación HISTÓRICA: ya se liberó. Es el estado que deja libre la
     * ranura sin borrar nada, así que es el que hay que poder construir en un
     * test sin repetir las dos columnas cada vez.
     */
    public function liberada(?Carbon $momento = null): static
    {
        return $this->state(fn () => [
            'activo' => false,
            'liberada_at' => $momento ?? Carbon::now(),
        ]);
    }

    public function enRanura(int $fingerprintId): static
    {
        return $this->state(fn () => ['fingerprint_id' => $fingerprintId]);
    }
}
