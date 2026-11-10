<?php

namespace App\Support\Asistencia;

use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\HoraOficial;
use Carbon\CarbonImmutable;

/**
 * La hoja diaria de asistencia de Producción, para imprimir y firmar. SOLO LECTURA.
 *
 * Lista a quien marca con el lector (el personal activo de Asistencia; los vendedores no
 * marcan) y a quien marcó ese día aunque después se haya dado de baja: la hoja es la
 * constancia de ese día. Entrada y salida son la primera y la última marca del día local,
 * en la hora de Asistencia; con una sola marca, la salida queda en blanco.
 *
 * Las marcas las trae {@see ConsultaJornadas}, la misma capa de «Marcas de hoy» y del
 * reporte: la hoja no arma su propia consulta.
 */
class HojaAsistencia
{
    public function __construct(
        private readonly HoraOficial $horaOficial,
        private readonly ConsultaJornadas $jornadas,
    ) {}

    /** @return list<array{nombre: string, entrada: ?string, salida: ?string}> */
    public function delDia(CarbonImmutable $fecha): array
    {
        $jornadas = $this->jornadas->porRango(FiltroAsistencia::vacio()->conRango($fecha, $fecha))
            ->keyBy('empleadoId');

        $personas = AsistenciaEmpleado::query()
            ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $jornadas->keys()))
            ->orderBy('apellidos')->orderBy('nombres')->orderBy('id')
            ->get();

        return $personas->map(function (AsistenciaEmpleado $persona) use ($jornadas) {
            $marcas = $jornadas->get($persona->id)?->marcaciones;

            return [
                'nombre' => $persona->nombreCompleto(),
                'entrada' => $this->hora($marcas?->first()?->marcado_at),
                'salida' => $marcas !== null && $marcas->count() > 1 ? $this->hora($marcas->last()->marcado_at) : null,
            ];
        })->all();
    }

    private function hora(?\DateTimeInterface $instante): ?string
    {
        return $instante === null ? null
            : CarbonImmutable::instance($instante)->setTimezone($this->horaOficial->zona())->format('H:i');
    }
}
