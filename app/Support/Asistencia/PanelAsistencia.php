<?php

namespace App\Support\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\HoraOficial;

/**
 * La pantalla «Hoy» de Asistencia. SOLO LECTURA.
 *
 * «El día» es el de la hora de negocio (HoraOficial, America/El_Salvador), nunca la
 * fecha UTC: a las 23:30 locales ya es mañana en UTC y la gente seguiría marcando hoy.
 * Las jornadas las arma {@see ConsultaJornadas}, la misma capa del reporte: la pantalla
 * no empareja marcaciones por su cuenta.
 *
 * «Todavía no marcaron» es un hecho (activos sin jornada en el día), no una ausencia:
 * no hay horario cargado con el que decidir que alguien llegó tarde o faltó.
 */
class PanelAsistencia
{
    public function __construct(
        private readonly HoraOficial $horaOficial,
        private readonly ConsultaJornadas $jornadas,
    ) {}

    /** Personas con jornada hoy y personal activo que todavía no marcó. */
    public function hoy(): array
    {
        $fecha = $this->horaOficial->fechaLocal($this->horaOficial->instante());
        $jornadas = $this->jornadas->porRango(FiltroAsistencia::desdeArray([
            'desde' => $fecha, 'hasta' => $fecha,
        ]));
        $activos = AsistenciaEmpleado::query()->where('activo', true)
            ->withCount(['huellas as huellas_vigentes' => fn ($q) => $q->where('activo', true)])
            ->orderBy('apellidos')->orderBy('nombres')->get();
        $faltan = $activos->whereNotIn('id', $jornadas->pluck('empleadoId'))->values();

        return [
            'fecha_hoy' => $fecha,
            'zona' => $this->horaOficial->zona(),
            'marcaron' => $jornadas->map(fn (Jornada $jornada) => [
                'persona' => $jornada->empleado,
                'primera_entrada' => $jornada->primeraEntrada()?->marcado_at,
                // Una nueva entrada después de una salida vuelve a dejarla adentro.
                'ultima_salida' => $jornada->marcaciones->last()?->tipo === TipoMarcacion::Entrada
                    ? null : $jornada->ultimaSalida()?->marcado_at,
                'horas' => $jornada->trabajadoLegible(),
                'exacto' => $jornada->tiempoEsExacto(),
            ]),
            'faltan' => $faltan,
            'conteos' => ['marcaron' => $jornadas->count(), 'faltan' => $faltan->count(), 'activos' => $activos->count()],
            'empleados_sin_huella' => $activos->where('huellas_vigentes', 0)->count(),
        ];
    }
}
