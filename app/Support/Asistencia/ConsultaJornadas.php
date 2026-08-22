<?php

namespace App\Support\Asistencia;

use App\Enums\Asistencia\EstadoJornada;
use App\Models\Asistencia\AsistenciaMarcacion;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Arma jornadas a partir de las marcaciones. EL SEAM de esta fase.
 *
 * ─────────────────────── Se apoya en la Fase 3, no la esquiva ───────────────────────
 *
 * NO consulta `AsistenciaMarcacion` por su cuenta: pide los datos a
 * {@see ConsultaAsistencia} con un {@see FiltroAsistencia}. Es deliberado. Toda
 * la garantía de la fase anterior —filtrar por `fecha_local` y no por el instante
 * UTC, aprovechar los índices, no inventar nada cuando falta el lector— vive ahí,
 * y una segunda consulta escrita acá la perdería en silencio.
 *
 * La cadena queda así, y es la que el módulo de Formatos va a usar entera:
 *
 *     Formatos -> ConsultaJornadas -> ConsultaAsistencia -> datos reales
 *
 * ────────────────────────── Se agrupa por DÍA LOCAL ──────────────────────────
 *
 * Por `fecha_local`, la columna, nunca volviendo a derivarla de `marcado_at`. En
 * El Salvador (UTC−6) una marcación de las 19:30 del día 5 se guarda como 01:30
 * UTC del día 6: derivar el día del instante movería el turno de la tarde entero.
 * La Fase 3 dejó eso protegido y acá no se rompe.
 *
 * ───────────────────────── Qué NO calcula, otra vez ─────────────────────────
 *
 * Tardanzas, horas extra, ausencias, feriados, almuerzos «esperados» y jornadas
 * incumplidas. Todo eso necesita una hora oficial de entrada, una jornada pactada
 * y un calendario laboral, y ninguna de las tres existe. Acá se suman los tramos
 * que hay y se cuentan los que quedaron sin cerrar.
 *
 * Un día SIN marcaciones no produce jornada. No es «ausencia» —eso presupone
 * saber que ese día se trabajaba—: es un día del que no hay nada que decir.
 */
class ConsultaJornadas
{
    /** Jornadas por página en la pantalla de reporte. */
    public const POR_PAGINA = 50;

    public function __construct(private readonly ConsultaAsistencia $marcaciones) {}

    /**
     * LA ENTRADA PRINCIPAL. Todas las jornadas que caen dentro del filtro,
     * ordenadas por fecha (descendente) y persona.
     *
     * `$estado` filtra DESPUÉS de armarlas, y no puede ser de otro modo: el estado
     * es derivado, no una columna, así que no existe un `where` que lo aplique.
     *
     * @return Collection<int, Jornada>
     */
    public function porRango(FiltroAsistencia $filtro, ?EstadoJornada $estado = null): Collection
    {
        // Ascendente: dentro de un día, las marcaciones tienen que llegar en el
        // orden en que ocurrieron para poder emparejarlas.
        $marcaciones = $this->marcaciones->todas($filtro->ascendente());

        $jornadas = $marcaciones
            // La clave lleva las dos dimensiones de una jornada: quién y qué día.
            ->groupBy(fn (AsistenciaMarcacion $m) => $m->asistencia_empleado_id.'|'.$m->fecha_local->format('Y-m-d'))
            ->map(function (Collection $delDia, string $clave) {
                [$empleadoId, $fecha] = explode('|', $clave);

                return Jornada::de((int) $empleadoId, CarbonImmutable::parse($fecha), $delDia);
            })
            ->values();

        if ($estado !== null) {
            $jornadas = $jornadas->filter(fn (Jornada $j) => $j->estado === $estado)->values();
        }

        return $this->ordenar($jornadas, $filtro->ascendente);
    }

    /**
     * Las jornadas de UNA persona. Atajo del anterior, para que quien solo quiera
     * a alguien no tenga que acordarse de componer el filtro.
     *
     * @return Collection<int, Jornada>
     */
    public function deEmpleado(int $empleadoId, FiltroAsistencia $filtro, ?EstadoJornada $estado = null): Collection
    {
        return $this->porRango($filtro->conEmpleado($empleadoId), $estado);
    }

    /**
     * Agrupadas por persona: una hoja por empleado, que es como se arma casi
     * cualquier formato de asistencia.
     *
     * @return Collection<int, Collection<int, Jornada>>
     */
    public function porEmpleado(FiltroAsistencia $filtro, ?EstadoJornada $estado = null): Collection
    {
        return $this->porRango($filtro, $estado)->groupBy(fn (Jornada $j) => $j->empleadoId);
    }

    /**
     * Para la pantalla. Se pagina en memoria y no en la base a propósito: una
     * jornada no es una fila, es el resultado de agrupar y emparejar varias, y no
     * hay `LIMIT` que se pueda aplicar a algo que todavía no existe.
     *
     * El coste está acotado por el filtro —la pantalla exige un rango y ofrece el
     * mes en curso por defecto—, no por este método.
     */
    public function paginar(FiltroAsistencia $filtro, ?EstadoJornada $estado = null, int $porPagina = self::POR_PAGINA): LengthAwarePaginator
    {
        $todas = $this->porRango($filtro, $estado);
        $pagina = Paginator::resolveCurrentPage();

        return (new LengthAwarePaginator(
            $todas->forPage($pagina, $porPagina)->values(),
            $todas->count(),
            $porPagina,
            $pagina,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        ))->withQueryString();
    }

    /**
     * Totales del conjunto. CONTEOS y una suma de tiempo; nada más.
     *
     * `tiempo_exacto` es la advertencia que hace honesto el total: si alguna
     * jornada quedó abierta, las horas que se muestran son un MÍNIMO. Un total sin
     * esa marca invita a tomarlo por definitivo.
     *
     * @return array{
     *     jornadas: int, personas: int, dias: int,
     *     completas: int, abiertas: int, irregulares: int,
     *     trabajado_segundos: int, trabajado_horas: float, tiempo_exacto: bool
     * }
     */
    public function resumen(FiltroAsistencia $filtro, ?EstadoJornada $estado = null): array
    {
        $jornadas = $this->porRango($filtro, $estado);
        $segundos = $jornadas->sum(fn (Jornada $j) => $j->trabajadoSegundos());

        return [
            'jornadas' => $jornadas->count(),
            'personas' => $jornadas->pluck('empleadoId')->unique()->count(),
            'dias' => $jornadas->map(fn (Jornada $j) => $j->fecha->format('Y-m-d'))->unique()->count(),
            'completas' => $jornadas->filter(fn (Jornada $j) => $j->estado === EstadoJornada::Completa)->count(),
            'abiertas' => $jornadas->filter(fn (Jornada $j) => $j->estado === EstadoJornada::Abierta)->count(),
            'irregulares' => $jornadas->filter(fn (Jornada $j) => $j->estado === EstadoJornada::Irregular)->count(),
            'trabajado_segundos' => (int) $segundos,
            'trabajado_horas' => round($segundos / 3600, 2),
            'tiempo_exacto' => $jornadas->every(fn (Jornada $j) => $j->tiempoEsExacto()),
        ];
    }

    /**
     * La jornada de UNA persona en UN día concreto, o null si ese día no tiene
     * marcaciones. Es la consulta puntual que va a hacer un formato al rellenar
     * una celda.
     */
    public function delDia(int $empleadoId, CarbonImmutable $dia): ?Jornada
    {
        $filtro = FiltroAsistencia::vacio()
            ->conEmpleado($empleadoId)
            ->conRango($dia, $dia);

        return $this->porRango($filtro)->first();
    }

    // ---------------------------------------------------------------- interno

    /**
     * Fecha primero, persona después: es el orden en el que se lee un reporte
     * diario. Dentro del mismo día, alfabético por apellido para que la lista no
     * baile entre consultas.
     *
     * @param  Collection<int, Jornada>  $jornadas
     * @return Collection<int, Jornada>
     */
    private function ordenar(Collection $jornadas, bool $ascendente): Collection
    {
        // Comparador explícito. `sortBy()` con varias closures invierte el orden
        // sobre colecciones de Eloquent, y aquí además hacen falta direcciones
        // MEZCLADAS: la fecha puede ir al revés, pero los apellidos nunca —
        // invertir la colección entera haría bailar la lista dentro de cada día.
        return $jornadas->sort(function (Jornada $a, Jornada $b) use ($ascendente) {
            $porFecha = $a->fecha->format('Y-m-d') <=> $b->fecha->format('Y-m-d');

            if ($porFecha !== 0) {
                return $ascendente ? $porFecha : -$porFecha;
            }

            return [$a->empleado?->apellidos ?? '', $a->empleado?->nombres ?? '', $a->empleadoId]
                <=> [$b->empleado?->apellidos ?? '', $b->empleado?->nombres ?? '', $b->empleadoId];
        })->values();
    }
}
