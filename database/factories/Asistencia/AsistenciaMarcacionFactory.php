<?php

namespace Database\Factories\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Marcaciones ya ocurridas, para los reportes y el historial que vienen.
 *
 * `marcado_at` y `fecha_local` se fijan JUNTOS en {@see en()}: son el mismo
 * instante visto en UTC y en la zona del módulo, y dejarlos derivar por separado
 * es la forma exacta en que un test acabaría comprobando una incoherencia que la
 * aplicación real no puede producir.
 *
 * NO se usa esta factory para probar el endpoint de marcación: ahí la marcación
 * tiene que nacer del POST, que es justamente lo que se está probando.
 *
 * @extends Factory<AsistenciaMarcacion>
 */
class AsistenciaMarcacionFactory extends Factory
{
    protected $model = AsistenciaMarcacion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $instante = Carbon::now('UTC');

        return [
            'asistencia_empleado_id' => AsistenciaEmpleado::factory(),
            'asistencia_dispositivo_id' => null,
            'asistencia_huella_id' => null,
            'tipo' => TipoMarcacion::Entrada,
            'marcado_at' => $instante,
            'fecha_local' => $this->fechaLocal($instante),
            'origen' => 'dispositivo',
            'ip' => null,
        ];
    }

    /** El instante exacto, con su día local coherente. */
    public function en(Carbon $instante): static
    {
        return $this->state(fn () => [
            'marcado_at' => $instante->copy()->setTimezone('UTC'),
            'fecha_local' => $this->fechaLocal($instante),
        ]);
    }

    public function tipo(TipoMarcacion $tipo): static
    {
        return $this->state(fn () => ['tipo' => $tipo]);
    }

    /** Corrección hecha a mano: sin lector y sin huella detrás. */
    public function manual(): static
    {
        return $this->state(fn () => [
            'origen' => 'manual',
            'asistencia_dispositivo_id' => null,
            'asistencia_huella_id' => null,
        ]);
    }

    private function fechaLocal(Carbon $instante): string
    {
        return $instante->copy()->setTimezone((string) config('asistencia.zona_horaria'))->format('Y-m-d');
    }
}
