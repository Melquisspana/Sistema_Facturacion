<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\EstadoDte;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqItem;
use App\Support\EjercicioControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Regla ÚNICA de qué CCF del Seguimiento pueden entrar en un PPQ nuevo. La usan la casilla
 * de la bandeja, el botón «Seleccionar todas las pendientes» y la creación del lote, que
 * vuelve a validarla en el servidor: la vista nunca decide sola (issue #8).
 */
class ElegibilidadPpqSeguimiento
{
    /** Pagos de menos que el TXT puede devolver para el próximo PPQ (nota `txt-…-fuera`). */
    private const PAGOS_DEVUELTOS_POR_TXT = [EstadoPagoCobro::Parcial, EstadoPagoCobro::Diferencia];

    /**
     * El pago no le impide entrar en un PPQ: está pendiente, o es un pago de menos que el TXT
     * devolvió para el próximo PPQ. Es la misma condición de {@see consulta()}; la usan también
     * {@see motivo()} y el quitar o borrar de un lote ({@see LiberarCcfDeLoteBorrado::motivoParaConservar()}).
     */
    public function pagoAdmitePpq(CobroDocumento $doc): bool
    {
        return $doc->pago_estado === EstadoPagoCobro::Pendiente
            || (in_array($doc->pago_estado, self::PAGOS_DEVUELTOS_POR_TXT, true) && self::devueltoPorTxt($doc->eventos())->exists());
    }

    private static function devueltoPorTxt(Builder|HasMany $eventos): Builder|HasMany
    {
        return $eventos->where('tipo', TipoEventoCobro::Nota->value)->where('referencia_linea', 'like', 'txt-%-fuera');
    }

    /**
     * Identidades ocupadas en un lote VIGENTE: número normalizado o «dte:{id}» => id del lote.
     * Salen de {@see itemsVigentes()}.
     *
     * @return Collection<string, int> clave normalizada => id del lote
     */
    public function claves(?int $sinLote = null): Collection
    {
        $claves = collect();
        foreach ($this->itemsVigentes($sinLote) as $item) {
            $clave = EjercicioControl::llave($item->numero_control, $item->ejercicio_control);
            if ($clave !== null) {
                $claves->put($clave, (int) $item->ppq_lote_id);
            }
            if ($item->dte_id !== null) {
                $claves->put('dte:'.$item->dte_id, (int) $item->ppq_lote_id);
            }
        }

        return $claves;
    }

    /**
     * Los renglones de los lotes VIGENTES: la única regla de qué es un lote vivo.
     *
     * No cuentan los lotes borrados (borrado blando: sus items quedan) ni los CCF que Calleja
     * no tomó en un caso («va en el siguiente PPQ»): esos tienen un evento `caso-…-fuera`
     * (o `txt-…-fuera`) posterior al item y perteneciente a ese mismo lote. Si después se
     * vuelven a meter en otro lote, el item nuevo es posterior al evento y vuelve a bloquear.
     *
     * @return Collection<int, PpqItem>
     */
    public function itemsVigentes(?int $sinLote = null): Collection
    {
        $items = PpqItem::whereHas('lote')
            ->when($sinLote !== null, fn ($q) => $q->where('ppq_lote_id', '!=', $sinLote))
            ->with('lote:id,observaciones')
            ->get(['id', 'ppq_lote_id', 'dte_id', 'numero_control', 'ejercicio_control', 'tipo_dte', 'conciliacion_estado', 'created_at']);

        $devoluciones = CobroEvento::where('tipo', TipoEventoCobro::Nota->value)
            ->where(fn ($q) => $q->where('referencia_linea', 'like', 'caso-%-fuera')->orWhere('referencia_linea', 'like', 'txt-%-fuera'))
            ->with('documento:id,dte_id,numero_control_norm,ejercicio_control')->get();
        $porControl = $devoluciones->groupBy(fn ($evento) => EjercicioControl::llave($evento->documento?->numero_control_norm, $evento->documento?->ejercicio_control));
        $porDte = $devoluciones->filter(fn ($evento) => $evento->documento?->dte_id !== null)
            ->groupBy(fn ($evento) => $evento->documento->dte_id);

        return $items->reject(function (PpqItem $item) use ($porControl, $porDte) {
            $clave = EjercicioControl::llave($item->numero_control, $item->ejercicio_control);
            $eventos = ($clave !== null ? $porControl->get($clave, collect()) : collect())
                ->merge($item->dte_id !== null ? $porDte->get($item->dte_id, collect()) : collect())->unique('id');

            return $eventos->contains(function ($evento) use ($item) {
                if ($item->created_at === null || $evento->created_at === null || $evento->created_at->lt($item->created_at)) {
                    return false;
                }
                if (array_key_exists('ppq_lote_id', $evento->datos ?? [])) {
                    return (int) $evento->datos['ppq_lote_id'] === (int) $item->ppq_lote_id;
                }
                $caso = $evento->datos['caso'] ?? null;

                return $caso !== null && preg_match('/\bCaso\s+'.preg_quote((string) $caso, '/').'\b(?!\d)/i',
                    (string) $item->lote?->observaciones) === 1;
            });
        })->values();
    }

    /**
     * Condiciones que se resuelven en SQL. Lo que falta (lote vigente) lo aplica {@see listos()}.
     *
     * @param  Builder<CobroDocumento>  $q
     * @return Builder<CobroDocumento>
     */
    public function consulta(Builder $q): Builder
    {
        return $q->where('tipo_dte', '03')
            ->where(fn ($p) => $p->where('pago_estado', EstadoPagoCobro::Pendiente->value)
                ->orWhere(fn ($p) => $p->whereIn('pago_estado', array_map(fn (EstadoPagoCobro $e) => $e->value, self::PAGOS_DEVUELTOS_POR_TXT))
                    ->whereHas('eventos', fn ($e) => self::devueltoPorTxt($e))))
            ->where('presentacion_estado', EstadoPresentacionCobro::SinPresentar->value)
            ->whereNotNull('ppq_albaran_id')
            ->where('revisar_historico', false)
            ->whereDoesntHave('dte', fn ($d) => $d->where('estado', EstadoDte::Invalidado->value))
            ->whereDoesntHave('eventos', fn ($e) => $e->enRevision());
    }

    /**
     * CCF que pueden entrar en un PPQ, con su monto.
     *
     * @param  Builder<CobroDocumento>  $q
     * @param  Collection<string, int>|null  $claves  las de {@see claves()}, si ya se calcularon
     * @return Collection<int, string> id => monto
     */
    public function listos(Builder $q, ?Collection $claves = null): Collection
    {
        $claves ??= $this->claves();

        return $this->consulta($q)->get(['id', 'dte_id', 'numero_control', 'ejercicio_control', 'monto'])
            ->reject(fn ($doc) => $claves->has(EjercicioControl::llave($doc->numero_control, $doc->ejercicio_control))
                || ($doc->dte_id !== null && $claves->has('dte:'.$doc->dte_id)))
            ->mapWithKeys(fn ($doc) => [$doc->id => (string) $doc->monto]);
    }

    /** @return array<int, array{reciente: bool, duplicado: ?string}> */
    public function avisos(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }
        $listos = $this->listos(CobroDocumento::whereKey($ids->all()));
        $docs = CobroDocumento::whereKey($listos->keys()->all())->with(['albaran', 'dte.clienteSucursal'])->get();
        $albaranes = $docs->pluck('ppq_albaran_id')->unique();
        $items = PpqItem::whereHas('lote')->whereIn('ppq_albaran_id', $albaranes)
            ->orderBy('id')->get()->groupBy('ppq_albaran_id');
        $otros = CobroDocumento::whereIn('ppq_albaran_id', $albaranes)
            ->where(fn ($q) => $q->where('pago_estado', EstadoPagoCobro::Pagado->value)
                ->orWhereIn('presentacion_estado', [EstadoPresentacionCobro::Presentada->value, EstadoPresentacionCobro::Recibida->value]))
            ->orderBy('id')->get()->groupBy('ppq_albaran_id');
        // Las devoluciones AC04 de todos, en una consulta: antes era una por documento.
        $devoluciones = app(DevolucionesAlbaran::class);
        $candidatas = $devoluciones->candidatasDe($docs);
        $avisos = [];
        foreach ($docs as $doc) {
            $item = ($items->get($doc->ppq_albaran_id) ?? collect())->first(fn ($item) => $item->dte_id !== $doc->dte_id
                && EjercicioControl::llave($item->numero_control, $item->ejercicio_control) !== EjercicioControl::llave($doc->numero_control, $doc->ejercicio_control));
            $otro = ($otros->get($doc->ppq_albaran_id) ?? collect())->first(fn ($otro) => $otro->id !== $doc->id);
            $duplicado = null;
            if ($item !== null) {
                $correlativo = preg_match('/(\d+)$/', (string) $item->numero_control, $m)
                    ? (ltrim($m[1], '0') ?: '0') : (string) $item->numero_control;
                $duplicado = "El albarán ya va con {$correlativo} en el PPQ #{$item->ppq_lote_id}";
            } elseif ($otro !== null) {
                $estado = $otro->pago_estado === EstadoPagoCobro::Pagado ? 'pagado' : 'presentado';
                $duplicado = "El albarán ya va con {$otro->correlativoCorto()}, {$estado}";
            }
            $avisos[$doc->id] = $devoluciones->avisos($doc, candidatas: $candidatas[$doc->id] ?? null) + [
                'duplicado' => $duplicado,
            ];
        }

        return $avisos;
    }

    /**
     * Por qué un CCF NO puede entrar en un PPQ, o null si puede.
     *
     * @param  Collection<string, int>  $claves  las de {@see claves()}
     */
    public function motivo(CobroDocumento $doc, Collection $claves): ?string
    {
        $enRevision = $doc->relationLoaded('eventos')
            ? $doc->eventos->contains(fn ($e) => $e->estado === EstadoEventoCobro::EnRevision)
            : $doc->tienePagosEnRevision();

        return match (true) {
            $doc->estaInvalidado() => 'fue invalidado en Hacienda',
            $doc->ppq_albaran_id === null => 'no tiene albarán (no entregado)',
            ! $this->pagoAdmitePpq($doc) => 'ya tiene pago',
            $enRevision => 'tiene pagos en revisión',
            $doc->revisar_historico => 'falta la revisión histórica',
            in_array($doc->presentacion_estado, [EstadoPresentacionCobro::Presentada, EstadoPresentacionCobro::Recibida], true) => 'ya se presentó',
            $doc->presentacion_estado === EstadoPresentacionCobro::Preparada,
            $claves->has(EjercicioControl::llave($doc->numero_control, $doc->ejercicio_control)),
            $doc->dte_id !== null && $claves->has('dte:'.$doc->dte_id) => 'ya está en un PPQ',
            default => null,
        };
    }
}
