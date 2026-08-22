<?php

namespace Database\Factories\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Órdenes de enrolamiento para las pruebas.
 *
 * `expira_at` se fija SIEMPRE junto al estado: una orden viva con vencimiento
 * pasado no es un caso de prueba válido por accidente —es el caso de «vencida», y
 * tiene su propio estado ({@see vencida()})—. Dejarlos derivar por separado es
 * cómo un test acabaría comprobando una combinación que la aplicación no produce.
 *
 * @extends Factory<AsistenciaOrdenEnrolamiento>
 */
class AsistenciaOrdenEnrolamientoFactory extends Factory
{
    protected $model = AsistenciaOrdenEnrolamiento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'asistencia_dispositivo_id' => AsistenciaDispositivo::factory(),
            'asistencia_empleado_id' => AsistenciaEmpleado::factory(),
            'estado' => EstadoOrdenEnrolamiento::Pendiente,
            'ranura_reservada' => $this->faker->unique()->numberBetween(0, 160),
            'ranura_manual' => false,
            'intento' => 1,
            'expira_at' => Carbon::now()->addMinutes(AsistenciaOrdenEnrolamiento::MINUTOS_DE_VIDA),
        ];
    }

    public function enRanura(int $ranura): static
    {
        return $this->state(fn () => ['ranura_reservada' => $ranura]);
    }

    /** Ya la recogió el lector: tiene token y espera el dedo. */
    public function tomada(string $token = 'token-de-prueba-de-la-orden'): static
    {
        return $this->state(fn () => [
            'estado' => EstadoOrdenEnrolamiento::Tomada,
            'token_hash' => AsistenciaOrdenEnrolamiento::hashDeToken($token),
            'tomada_at' => Carbon::now(),
        ]);
    }

    /**
     * Vencida de hecho pero todavía con estado vivo: es el estado REAL de una
     * orden abandonada antes de que alguien la mire, y el que las pruebas de
     * expiración necesitan poder construir.
     */
    public function vencida(): static
    {
        return $this->state(fn () => ['expira_at' => Carbon::now()->subMinute()]);
    }

    public function completada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoOrdenEnrolamiento::Completada,
            'finalizada_at' => Carbon::now(),
        ]);
    }

    public function fallida(MotivoFalloEnrolamiento $motivo = MotivoFalloEnrolamiento::FalloGuardado): static
    {
        return $this->state(fn () => [
            'estado' => EstadoOrdenEnrolamiento::Fallida,
            'motivo_fallo' => $motivo,
            'detalle' => $motivo->explicacion(),
            'finalizada_at' => Carbon::now(),
        ]);
    }
}
