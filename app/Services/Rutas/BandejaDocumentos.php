<?php

namespace App\Services\Rutas;

use App\Models\ClienteSucursal;
use App\Models\SalidaRutaDocumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bandeja transversal: los documentos de TODAS las salidas en una sola consulta.
 *
 * El detalle de una salida responde «¿cómo va este viaje?». Esta bandeja responde
 * la pregunta de la operación diaria, que es otra: «¿qué me falta?» —albaranes,
 * papeles, documentos sin meter al PPQ, cobros— sin importar de qué salida vengan.
 *
 * ─────────────────────────── Filtros duros y filtros derivados ───────────────────────────
 *
 * Los estados que más se quieren filtrar (entregado, en PPQ, pagado) NO son columnas:
 * se derivan de `ppq_albaranes`, `dtes` y `ppq_items` al momento de mirar. Reescribir
 * esas reglas como WHERE sería crear una segunda versión de la verdad que tarde o
 * temprano se despega de {@see AlbaranLocalizador}, {@see LocalizadorNotaCredito} y
 * {@see LocalizadorPpq}. Por eso el filtrado va en dos tiempos:
 *
 *   1. DUROS, en SQL   -> ruta, salida, sala, fechas, papel recibido, requiere NC.
 *                         Todas columnas reales, todas indexables.
 *   2. DERIVADOS, en PHP -> entrega, estado de cobro. Se aplican DESPUÉS de hidratar
 *                         con {@see SeguimientoDocumentos::hidratar()}, o sea con las
 *                         mismas reglas que pinta la pantalla de la salida. Un
 *                         documento nunca puede salir en un filtro y contradecir su
 *                         propia fila.
 *
 * Lo que hace viable el paso 2 es que el paso 1 SIEMPRE acota por fecha: la ventana
 * tiene un valor por defecto ({@see config('rutas.bandeja_dias')}) y no se puede
 * quitar, solo mover. Sin ese tope, «hidratar todo» crecería sin límite.
 *
 * ──────────────────────────── Por qué la fecha es la de la SALIDA ────────────────────────────
 *
 * Se filtra por `salidas_ruta.fecha_inicio` y no por la fecha del documento. No es un
 * atajo: en P002 `fecha_documento` queda NULL a propósito —la fecha vive en el DTE y
 * se lee de ahí— así que filtrar por esa columna dejaría fuera justamente el camino
 * principal. `fecha_inicio` existe siempre, en los dos caminos, y además es lo que se
 * pregunta de verdad: «qué salió en estas semanas».
 */
class BandejaDocumentos
{
    public const ENTREGA_ENTREGADO = 'entregado';

    public const ENTREGA_SIN_ALBARAN = 'sin_albaran';

    public const PAPEL_RECIBIDO = 'recibido';

    public const PAPEL_PENDIENTE = 'pendiente';

    /** No entró a ningún lote todavía. */
    public const PPQ_FUERA = 'fuera';

    /** Ya está en un lote, pero nadie lo pagó. */
    public const PPQ_PENDIENTE = 'pendiente';

    /** Conciliado contra el TXT de Calleja. */
    public const PPQ_PAGADO = 'pagado';

    public function __construct(private readonly SeguimientoDocumentos $seguimiento) {}

    /**
     * Documentos que cumplen los filtros, ya hidratados, más el resumen contado sobre
     * ESE mismo conjunto (no sobre la página que se ve).
     *
     * @param  array<string, mixed>  $filtros
     * @return array{documentos: Collection<int, SalidaRutaDocumento>, resumen: array<string, int>, desde: Carbon, hasta: Carbon}
     */
    public function consultar(array $filtros): array
    {
        [$desde, $hasta] = $this->ventana($filtros);

        $documentos = $this->seguimiento->hidratar(
            $this->consultaBase($filtros, $desde, $hasta)->get()
        );

        $documentos = $this->filtrarDerivados($documentos, $filtros)->values();

        return [
            'documentos' => $documentos,
            'resumen' => $this->seguimiento->resumen($documentos),
            'desde' => $desde,
            'hasta' => $hasta,
        ];
    }

    /**
     * Ventana de fechas. SIEMPRE acotada: si no vienen fechas se usan los
     * `rutas.bandeja_dias` hacia atrás y otros tantos hacia adelante.
     *
     * El tramo FUTURO no es simetría decorativa: una salida se crea PLANIFICADA con
     * fecha de la semana que viene y se le cargan documentos ese mismo día. Con el
     * tope en «hoy» esos documentos no aparecerían en la bandeja hasta el día de la
     * salida, que es justo cuando ya no hace falta buscarlos.
     *
     * El límite que importa para el volumen es el de atrás, que es donde el historial
     * se acumula; hacia adelante solo hay lo que alguien planificó a mano.
     *
     * Un rango invertido se endereza en vez de devolver una lista vacía que parece
     * «no hay nada» cuando en realidad fue un error de tipeo.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{0: Carbon, 1: Carbon}
     */
    public function ventana(array $filtros): array
    {
        $dias = (int) config('rutas.bandeja_dias', 60);

        $desde = $this->fecha($filtros['desde'] ?? null) ?? Carbon::today()->subDays($dias);
        $hasta = $this->fecha($filtros['hasta'] ?? null) ?? Carbon::today()->addDays($dias);

        return $desde->greaterThan($hasta) ? [$hasta, $desde] : [$desde, $hasta];
    }

    /**
     * Los filtros DUROS, sobre columnas reales.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<SalidaRutaDocumento>
     */
    private function consultaBase(array $filtros, Carbon $desde, Carbon $hasta): Builder
    {
        return SalidaRutaDocumento::query()
            ->select('salida_ruta_documentos.*')
            ->join('salidas_ruta', 'salidas_ruta.id', '=', 'salida_ruta_documentos.salida_ruta_id')
            ->with([
                'salida:id,ruta_id,fecha_inicio,fecha_fin_real,fecha_fin_estimada,estado',
                'salida.ruta:id,nombre',
                'dte:id,tipo_dte,estado,numero_control,numero_orden_compra,fecha_emision,total_pagar,cliente_id,cliente_sucursal_id',
                'dte.cliente:id,nombre',
                'dte.clienteSucursal:id,nombre,codigo',
                'clienteSucursal:id,nombre,codigo',
                'documentacionRecibidaPor:id,name',
            ])
            ->whereBetween('salidas_ruta.fecha_inicio', [$desde->toDateString(), $hasta->toDateString()])
            ->when(filled($filtros['ruta_id'] ?? null), fn (Builder $q) => $q->where('salidas_ruta.ruta_id', (int) $filtros['ruta_id']))
            ->when(filled($filtros['salida_id'] ?? null), fn (Builder $q) => $q->where('salida_ruta_documentos.salida_ruta_id', (int) $filtros['salida_id']))
            ->when(filled($filtros['sucursal_id'] ?? null), fn (Builder $q) => $this->filtrarSala($q, (int) $filtros['sucursal_id']))
            ->when(($filtros['papel'] ?? null) === self::PAPEL_RECIBIDO, fn (Builder $q) => $q->whereNotNull('documentacion_fisica_recibida_at'))
            ->when(($filtros['papel'] ?? null) === self::PAPEL_PENDIENTE, fn (Builder $q) => $q->whereNull('documentacion_fisica_recibida_at'))
            ->when(($filtros['requiere_nc'] ?? null) === '1', fn (Builder $q) => $q->where('requiere_nc', true))
            ->when(($filtros['requiere_nc'] ?? null) === '0', fn (Builder $q) => $q->where('requiere_nc', false))
            // Lo más reciente primero: la bandeja se abre para ver lo que está pasando.
            ->orderByDesc('salidas_ruta.fecha_inicio')
            ->orderByDesc('salidas_ruta.id')
            ->orderBy('salida_ruta_documentos.numero_control');
    }

    /**
     * Filtro por sala. Tiene dos ramas porque las dos series guardan la sala en sitios
     * distintos: P002 trae `cliente_sucursal_id` copiado del DTE, y el histórico P001
     * normalmente NO resuelve a una sucursal fiscal y solo conserva el nombre. Buscar
     * únicamente por el id dejaría fuera, en silencio, a todos los históricos de esa
     * misma sala.
     *
     * @param  Builder<SalidaRutaDocumento>  $query
     */
    private function filtrarSala(Builder $query, int $sucursalId): Builder
    {
        $nombre = ClienteSucursal::whereKey($sucursalId)->value('nombre');

        return $query->where(function (Builder $q) use ($sucursalId, $nombre) {
            $q->where('salida_ruta_documentos.cliente_sucursal_id', $sucursalId);

            if (filled($nombre)) {
                $q->orWhere(function (Builder $historico) use ($nombre) {
                    $historico->whereNull('salida_ruta_documentos.cliente_sucursal_id')
                        ->where('salida_ruta_documentos.sala_nombre', $nombre);
                });
            }
        });
    }

    /**
     * Los filtros DERIVADOS, sobre la colección ya hidratada. Preguntan exactamente
     * lo mismo que muestra cada fila, con los mismos métodos.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, SalidaRutaDocumento>
     */
    private function filtrarDerivados(Collection $documentos, array $filtros): Collection
    {
        $entrega = $filtros['entrega'] ?? null;
        $ppq = $filtros['ppq'] ?? null;

        return $documentos
            ->when($entrega === self::ENTREGA_ENTREGADO, fn (Collection $c) => $c->filter(fn (SalidaRutaDocumento $d) => $d->entregado()))
            ->when($entrega === self::ENTREGA_SIN_ALBARAN, fn (Collection $c) => $c->filter(fn (SalidaRutaDocumento $d) => ! $d->entregado()))
            ->when($ppq === self::PPQ_FUERA, fn (Collection $c) => $c->filter(fn (SalidaRutaDocumento $d) => ! $d->enPpq()))
            // «En PPQ sin pagar»: está en un lote y no está conciliado como pagado. Un
            // item en estado `aplicada` caería acá, pero eso solo le pasa a una NC y
            // este módulo transporta CCF: en la práctica no ocurre.
            ->when($ppq === self::PPQ_PENDIENTE, fn (Collection $c) => $c->filter(fn (SalidaRutaDocumento $d) => $d->enPpq() && ! $d->pagado()))
            ->when($ppq === self::PPQ_PAGADO, fn (Collection $c) => $c->filter(fn (SalidaRutaDocumento $d) => $d->pagado()));
    }

    private function fecha(mixed $valor): ?Carbon
    {
        if (! filled($valor)) {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            // Una fecha ilegible se ignora y manda el valor por defecto. Es preferible
            // a devolver una lista vacía que parece «no hay nada» cuando en realidad
            // hubo un error de tipeo.
            return null;
        }
    }
}
