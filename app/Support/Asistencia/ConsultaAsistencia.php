<?php

namespace App\Support\Asistencia;

use App\Models\Asistencia\AsistenciaMarcacion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * LA consulta de marcaciones. Un solo sitio para «qué se marcó, quién y cuándo».
 *
 * ─────────────────────────────── Por qué existe ───────────────────────────────
 *
 * Porque el módulo de Formatos va a necesitar estos mismos datos, y la diferencia
 * entre estas dos formas decide si ese módulo se puede construir o no:
 *
 *     Formatos -> ConsultaAsistencia -> datos reales          ✅
 *     Formatos -> copiar el `where` del controlador           ❌
 *
 * La segunda parece más rápida el primer día y garantiza que, en cuanto alguien
 * corrija un criterio, la pantalla y el documento empiecen a decir cosas distintas
 * sobre el mismo mes. Por eso acá no hay nada de HTTP: los criterios entran como
 * {@see FiltroAsistencia}, que se construye igual desde un formulario, desde una
 * definición de formato o desde un comando.
 *
 * ────────────────────── Las fechas se filtran por DÍA LOCAL ──────────────────────
 *
 * SIEMPRE sobre `fecha_local`, nunca sobre `marcado_at`. Dos razones y las dos
 * mandan:
 *
 *  1. CORRECCIÓN. En El Salvador (UTC−6) una marcación de las 19:30 del día 5 se
 *     guarda como 01:30 UTC del día 6. Filtrar por el instante la sacaría del día
 *     5 y la metería en el 6 — sin error, sin aviso, con el turno de la tarde
 *     entero desplazado.
 *  2. RENDIMIENTO. `fecha_local` está indexada, sola y junto al empleado.
 *     Convertir `marcado_at` a hora local dentro del `where` exigiría funciones de
 *     zona horaria que difieren entre MySQL y SQLite y que ningún índice puede
 *     aprovechar.
 *
 * Comprobado con EXPLAIN sobre MySQL: empleado+rango usa
 * `asistencia_marc_empleado_fecha_idx`, el rango solo usa `asistencia_marc_fecha_idx`
 * y el lector usa el índice de su clave foránea. **No hizo falta esquema nuevo.**
 *
 * ─────────────────────────────── Solo lectura ───────────────────────────────
 *
 * Ni un `update`, ni un `insert`, ni un `delete`. Las marcaciones son un libro de
 * solo-añadir (la tabla ni siquiera tiene `updated_at`) y esta clase solo lee.
 *
 * ─────────────────── Lo que deliberadamente NO calcula ───────────────────
 *
 * Horas trabajadas, tardanzas, ausencias y jornadas. Todo eso necesita horarios, y
 * los horarios no existen todavía: emparejar entrada con salida sin saber qué es
 * una jornada es adivinar. {@see resumen()} cuenta hechos —cuántas marcaciones,
 * cuántas personas, cuántos días— y ahí se detiene.
 */
class ConsultaAsistencia
{
    /** Marcaciones por página en la pantalla de historial. */
    public const POR_PAGINA = 50;

    /**
     * EL SEAM. Devuelve la consulta compuesta, sin ejecutar, con las tres
     * relaciones ya declaradas para que nadie caiga en N+1 al recorrerla.
     *
     * Es público a propósito: quien necesite algo que estos métodos no ofrecen
     * —recorrer en lotes con `lazyById()`, agregar un `join`, contar de otra
     * forma— compone sobre esto en vez de escribir su propio `where`. Ese es el
     * punto de que exista la clase.
     *
     * @return Builder<AsistenciaMarcacion>
     */
    public function query(FiltroAsistencia $filtro): Builder
    {
        return AsistenciaMarcacion::query()
            ->with([
                'empleado',
                // Pueden ser NULL en una corrección manual, y también si algún día
                // se borrara el lector (`nullOnDelete`). Se cargan igual y quien
                // pinte decide qué decir; acá no se inventa nada.
                'dispositivo',
                'huella',
            ])
            ->when($filtro->empleadoId !== null, fn (Builder $q) => $q->where('asistencia_empleado_id', $filtro->empleadoId))
            ->when($filtro->dispositivoId !== null, fn (Builder $q) => $q->where('asistencia_dispositivo_id', $filtro->dispositivoId))
            ->when($filtro->tipo !== null, fn (Builder $q) => $q->where('tipo', $filtro->tipo->value))
            ->when($filtro->origen !== null, fn (Builder $q) => $q->where('origen', $filtro->origen))
            // Rango INCLUSIVO en los dos extremos: «del 1 al 31» incluye el 31.
            ->when($filtro->desde !== null, fn (Builder $q) => $q->whereDate('fecha_local', '>=', $filtro->desde))
            ->when($filtro->hasta !== null, fn (Builder $q) => $q->whereDate('fecha_local', '<=', $filtro->hasta))
            // `id` como desempate: sin él, dos marcaciones del mismo segundo pueden
            // cambiar de orden entre páginas y una fila aparecería dos veces o
            // ninguna. `marcado_at` va primero porque es el instante real.
            ->orderBy('marcado_at', $filtro->ascendente ? 'asc' : 'desc')
            ->orderBy('id', $filtro->ascendente ? 'asc' : 'desc');
    }

    /** Para la pantalla. Conserva los filtros en los enlaces de página. */
    public function paginar(FiltroAsistencia $filtro, int $porPagina = self::POR_PAGINA): LengthAwarePaginator
    {
        return $this->query($filtro)->paginate($porPagina)->withQueryString();
    }

    /**
     * Todas las que cumplan el filtro, en memoria. Para documentos y reportes de
     * tamaño acotado —un mes, un departamento—.
     *
     * Para rangos grandes, componé sobre {@see query()} con `lazyById()` en vez de
     * pedir esto: la diferencia entre un formato mensual y uno anual es la
     * diferencia entre cientos de filas y decenas de miles.
     *
     * @return Collection<int, AsistenciaMarcacion>
     */
    public function todas(FiltroAsistencia $filtro): Collection
    {
        return $this->query($filtro)->get();
    }

    /**
     * Agrupadas por persona, que es como se construye casi cualquier formato de
     * asistencia: una hoja por empleado.
     *
     * @return Collection<int, Collection<int, AsistenciaMarcacion>> id de empleado ⇒ sus marcaciones
     */
    public function porEmpleado(FiltroAsistencia $filtro): Collection
    {
        return $this->todas($filtro)->groupBy('asistencia_empleado_id');
    }

    /**
     * Agrupadas por DÍA LOCAL. La otra forma natural de un formato —una fila por
     * día— y la base sobre la que la fase de jornadas emparejará entradas con
     * salidas cuando existan los horarios que digan cómo.
     *
     * @return Collection<string, Collection<int, AsistenciaMarcacion>> 'Y-m-d' ⇒ sus marcaciones
     */
    public function porDia(FiltroAsistencia $filtro): Collection
    {
        return $this->todas($filtro)->groupBy(
            fn (AsistenciaMarcacion $m) => $m->fecha_local->format('Y-m-d')
        );
    }

    public function contar(FiltroAsistencia $filtro): int
    {
        return $this->query($filtro)->count();
    }

    /**
     * CONTEOS, no cálculos. Cuántas marcaciones hay, de qué tipo, de cuánta gente y
     * en cuántos días distintos. Nada de horas ni de puntualidad: eso necesita
     * horarios y los horarios no existen.
     *
     * Se resuelve en la base y no en PHP para que sirva igual sobre un día que
     * sobre un año, sin traerse las filas.
     *
     * @return array{total: int, entradas: int, salidas: int, personas: int, dias: int}
     */
    public function resumen(FiltroAsistencia $filtro): array
    {
        $base = fn () => $this->query($filtro)->withoutEagerLoads()->reorder();

        return [
            'total' => $base()->count(),
            'entradas' => $base()->where('tipo', 'entrada')->count(),
            'salidas' => $base()->where('tipo', 'salida')->count(),
            'personas' => $base()->distinct()->count('asistencia_empleado_id'),
            'dias' => $base()->distinct()->count('fecha_local'),
        ];
    }

    /**
     * Las últimas N de una persona. Es lo que se enseña en su ficha: suficiente
     * para ver si el lector la está registrando, sin convertir la ficha en un
     * historial con filtros que ya existe en su propia pantalla.
     *
     * @return Collection<int, AsistenciaMarcacion>
     */
    public function ultimasDe(int $empleadoId, int $cuantas = 10): Collection
    {
        return $this->query(FiltroAsistencia::vacio()->conEmpleado($empleadoId))
            ->limit($cuantas)
            ->get();
    }
}
