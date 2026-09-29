<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * Bitácora de DESCARGAS PREPARADAS de archivos entregables (formatos de NC, solicitudes de
 * quedan), sobre la tabla `activity_log` que ya usa el proyecto: sujeto, actor, instante
 * y propiedades. Sin migración.
 *
 * «Preparada» es la palabra exacta: el sistema armó una respuesta con el archivo ya
 * verificado. Eso NO prueba que el navegador terminó de recibirlo ni, mucho menos, que se
 * entregó al cliente.
 *
 * Solo se registra DESPUÉS de tener el archivo listo y verificado, y en la misma
 * transacción que el contador del sujeto (`registrarDescarga()`): una copia inservible o
 * una generación fallida no dejan entrada. Las descargas anteriores a esta bitácora solo
 * dejaron el contador y la primera fecha; no se reconstruyen.
 */
class RegistroDescargas
{
    public const LOG = 'descarga_preparada';

    /**
     * Cuenta la descarga en el sujeto y deja la entrada con la huella del archivo QUE SE
     * VA A SERVIR (se calcula sobre el temporal, no se copia del registro).
     *
     * @param  Model  $sujeto  debe exponer registrarDescarga() (NcExportacion, CobroSolicitud)
     */
    public function registrar(Model $sujeto, string $rutaTemporal, string $nombreArchivo, ?User $usuario, string $referencia): void
    {
        $hash = hash_file('sha256', $rutaTemporal);

        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular la huella del archivo preparado.');
        }

        DB::transaction(function () use ($sujeto, $hash, $nombreArchivo, $usuario, $referencia) {
            // Relectura bloqueante: dos descargas simultáneas del mismo sujeto se serializan
            // y la segunda suma sobre el contador que dejó la primera. Con el modelo ya
            // cargado, ambas escribirían N+1 y quedarían dos entradas para un solo +1.
            $fila = $sujeto->newQuery()->whereKey($sujeto->getKey())->lockForUpdate()->firstOrFail();
            $fila->registrarDescarga();

            // El llamador sigue viendo el contador y la primera fecha ya guardados.
            $sujeto->setRawAttributes($fila->getAttributes(), true);

            activity(self::LOG)
                ->performedOn($fila)
                ->causedBy($usuario)
                ->withProperties([
                    'referencia' => $referencia,
                    'archivo' => $nombreArchivo,
                    'sha256' => $hash,
                ])
                ->log('preparó la descarga del archivo');
        });
    }

    /**
     * Últimas descargas preparadas de UN sujeto, de la más reciente a la más vieja.
     *
     * @return Collection<int, Activity>
     */
    public function historial(Model $sujeto, int $limite = 20): Collection
    {
        return $this->deSujeto($sujeto)
            ->with('causer')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();
    }

    /**
     * El historial COMPLETO de un sujeto, paginado con su propio parámetro. Orden por id:
     * estable entre páginas aunque dos entradas compartan segundo.
     *
     * @return LengthAwarePaginator<Activity>
     */
    public function paginado(Model $sujeto, int $porPagina, string $parametro): LengthAwarePaginator
    {
        return $this->deSujeto($sujeto)
            ->with('causer')
            ->orderByDesc('id')
            ->paginate($porPagina, ['*'], $parametro)
            ->withQueryString();
    }

    /** Cuántas descargas preparadas quedaron registradas para el sujeto. */
    public function cantidad(Model $sujeto): int
    {
        return $this->deSujeto($sujeto)->count();
    }

    /** @return Builder<Activity> */
    private function deSujeto(Model $sujeto): Builder
    {
        return Activity::query()
            ->where('log_name', self::LOG)
            ->where('subject_type', $sujeto->getMorphClass())
            ->where('subject_id', $sujeto->getKey());
    }

    /**
     * La más reciente de cada sujeto, en UNA consulta, para listados.
     *
     * @param  EloquentCollection<int, Model>  $sujetos
     * @return array<int, Activity> id del sujeto => entrada
     */
    public function ultimas(EloquentCollection $sujetos): array
    {
        if ($sujetos->isEmpty()) {
            return [];
        }

        $tipo = $sujetos->first()->getMorphClass();

        // Solo la ÚLTIMA de cada sujeto (MAX(id) agrupado): no se traen todas.
        return Activity::query()
            ->whereIn('id', Activity::query()
                ->selectRaw('MAX(id)')
                ->where('log_name', self::LOG)
                ->where('subject_type', $tipo)
                ->whereIn('subject_id', $sujetos->modelKeys())
                ->groupBy('subject_id'))
            ->with('causer')
            ->get()
            ->keyBy(fn (Activity $a) => (int) $a->subject_id)
            ->all();
    }
}
