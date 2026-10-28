<?php

namespace App\Support\Asistencia;

use Illuminate\Support\Collection;

/** La misma fila por persona para la pantalla y el Excel. */
class ReporteAsistencia
{
    public function __construct(private readonly ConsultaJornadas $jornadas) {}

    public function filas(FiltroAsistencia $filtro): Collection
    {
        return $this->jornadas->porEmpleado($filtro)->map(fn (Collection $dias) => [
            'nombre' => $dias->first()->empleado?->nombreCompleto() ?? 'Persona sin nombre',
            'dias' => $dias->map(fn (Jornada $j) => $j->fecha->format('Y-m-d'))->unique()->count(),
            'horas' => round($dias->sum(fn (Jornada $j) => $j->trabajadoSegundos()) / 3600, 2),
            'exacto' => $dias->every(fn (Jornada $j) => $j->tiempoEsExacto()),
        ])->sortBy('nombre')->values();
    }
}
