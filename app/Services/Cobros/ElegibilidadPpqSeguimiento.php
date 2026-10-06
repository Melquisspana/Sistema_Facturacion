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
use App\Support\IdentidadPpq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Regla ÚNICA de qué CCF del Seguimiento pueden entrar en un PPQ nuevo. La usan la casilla
 * de la bandeja, el botón «Seleccionar todas las pendientes» y la creación del lote, que
 * vuelve a validarla en el servidor: la vista nunca decide sola (issue #8).
 */
class ElegibilidadPpqSeguimiento
{
    /**
     * Identidades ocupadas en un lote VIGENTE: número normalizado o «dte:{id}» => id del lote.
     *
     * No cuentan los lotes borrados (borrado blando: sus items quedan) ni los CCF que Calleja
     * no tomó en un caso («va en el siguiente PPQ»): esos tienen un evento `caso-…-fuera`
     * posterior al item y perteneciente a ese mismo lote. Si después se vuelven a meter
     * en otro lote, el item nuevo es posterior al evento y vuelve a bloquear.
     *
     * @return Collection<string, int> clave normalizada => id del lote
     */
    public function claves(?int $sinLote = null): Collection
    {
        $items = PpqItem::whereHas('lote')
            ->when($sinLote !== null, fn ($q) => $q->where('ppq_lote_id', '!=', $sinLote))
            ->with('lote:id,observaciones')
            ->get(['ppq_lote_id', 'dte_id', 'numero_control', 'created_at']);

        $devoluciones = CobroEvento::where('tipo', TipoEventoCobro::Nota->value)
            ->where('referencia_linea', 'like', 'caso-%-fuera')
            ->with('documento:id,dte_id,numero_control_norm')->get();
        $porControl = $devoluciones->groupBy(fn ($evento) => $evento->documento?->numero_control_norm);
        $porDte = $devoluciones->filter(fn ($evento) => $evento->documento?->dte_id !== null)
            ->groupBy(fn ($evento) => $evento->documento->dte_id);

        $claves = collect();
        foreach ($items as $item) {
            $clave = IdentidadPpq::normalizar($item->numero_control);
            $eventos = ($clave !== null ? $porControl->get($clave, collect()) : collect())
                ->merge($item->dte_id !== null ? $porDte->get($item->dte_id, collect()) : collect())->unique('id');
            $liberado = $eventos->contains(function ($evento) use ($item) {
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
            if ($liberado) {
                continue;
            }
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
     * Condiciones que se resuelven en SQL. Lo que falta (lote vigente) lo aplica {@see listos()}.
     *
     * @param  Builder<CobroDocumento>  $q
     * @return Builder<CobroDocumento>
     */
    public function consulta(Builder $q): Builder
    {
        return $q->where('tipo_dte', '03')
            ->where('pago_estado', EstadoPagoCobro::Pendiente->value)
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

        return $this->consulta($q)->get(['id', 'dte_id', 'numero_control', 'monto'])
            ->reject(fn ($doc) => $claves->has(IdentidadPpq::normalizar($doc->numero_control))
                || ($doc->dte_id !== null && $claves->has('dte:'.$doc->dte_id)))
            ->mapWithKeys(fn ($doc) => [$doc->id => (string) $doc->monto]);
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
            $doc->pago_estado !== EstadoPagoCobro::Pendiente => 'ya tiene pago',
            $enRevision => 'tiene pagos en revisión',
            $doc->revisar_historico => 'falta la revisión histórica',
            in_array($doc->presentacion_estado, [EstadoPresentacionCobro::Presentada, EstadoPresentacionCobro::Recibida], true) => 'ya se presentó',
            $doc->presentacion_estado === EstadoPresentacionCobro::Preparada,
            $claves->has(IdentidadPpq::normalizar($doc->numero_control)),
            $doc->dte_id !== null && $claves->has('dte:'.$doc->dte_id) => 'ya está en un PPQ',
            default => null,
        };
    }
}
