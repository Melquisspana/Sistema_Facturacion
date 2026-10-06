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
     * Números de control normalizados que ya ocupan un lote VIGENTE, con el id de ese lote.
     *
     * No cuentan los lotes borrados (borrado blando: sus items quedan) ni los CCF que Calleja
     * no tomó en un caso («va en el siguiente PPQ»): esos tienen un evento `caso-…-fuera`
     * posterior al item. Si después se vuelven a meter en otro lote, el item nuevo es
     * posterior al evento y vuelve a bloquear.
     *
     * @return Collection<string, int> clave normalizada => id del lote
     */
    public function claves(?int $sinLote = null): Collection
    {
        $items = PpqItem::whereHas('lote')
            ->when($sinLote !== null, fn ($q) => $q->where('ppq_lote_id', '!=', $sinLote))
            ->get(['ppq_lote_id', 'numero_control', 'created_at']);

        $devoluciones = CobroEvento::where('tipo', TipoEventoCobro::Nota->value)
            ->where('referencia_linea', 'like', 'caso-%-fuera')
            ->join('cobro_documentos', 'cobro_documentos.id', '=', 'cobro_eventos.cobro_documento_id')
            ->select('numero_control_norm')->selectRaw('MAX(cobro_eventos.created_at) as devuelto_en')
            ->groupBy('numero_control_norm')->pluck('devuelto_en', 'numero_control_norm');

        $claves = collect();
        foreach ($items as $item) {
            $clave = IdentidadPpq::normalizar($item->numero_control);
            if ($clave === null) {
                continue;
            }
            $devuelto = $devoluciones->get($clave);
            if ($devuelto === null || $item->created_at === null || $item->created_at->gt($devuelto)) {
                $claves->put($clave, (int) $item->ppq_lote_id);
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

        return $this->consulta($q)->get(['id', 'numero_control', 'monto'])
            ->reject(fn ($doc) => $claves->has(IdentidadPpq::normalizar($doc->numero_control)))
            ->mapWithKeys(fn ($doc) => [$doc->id => (string) $doc->monto]);
    }

    /** @return array<int, array{reciente: bool, duplicado: ?string}> */
    public function avisos(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }
        $listos = $this->listos(CobroDocumento::whereKey($ids->all()));
        $docs = CobroDocumento::whereKey($listos->keys()->all())->with('albaran')->get();
        $albaranes = $docs->pluck('ppq_albaran_id')->unique();
        $items = PpqItem::whereHas('lote')->whereIn('ppq_albaran_id', $albaranes)
            ->orderBy('id')->get()->groupBy('ppq_albaran_id');
        $otros = CobroDocumento::whereIn('ppq_albaran_id', $albaranes)
            ->where(fn ($q) => $q->where('pago_estado', EstadoPagoCobro::Pagado->value)
                ->orWhereIn('presentacion_estado', [EstadoPresentacionCobro::Presentada->value, EstadoPresentacionCobro::Recibida->value]))
            ->orderBy('id')->get()->groupBy('ppq_albaran_id');
        $limite = now()->subDays(max(0, (int) config('cobros.dias_albaran_reciente', 5)));
        $avisos = [];
        foreach ($docs as $doc) {
            $item = ($items->get($doc->ppq_albaran_id) ?? collect())->first(fn ($item) => $item->dte_id !== $doc->dte_id
                && IdentidadPpq::normalizar($item->numero_control) !== IdentidadPpq::normalizar($doc->numero_control));
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
            $avisos[$doc->id] = [
                'reciente' => $doc->albaran?->created_at !== null && $doc->albaran->created_at->betweenIncluded($limite, now()),
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
            $doc->pago_estado !== EstadoPagoCobro::Pendiente => 'ya tiene pago',
            $enRevision => 'tiene pagos en revisión',
            $doc->revisar_historico => 'falta la revisión histórica',
            in_array($doc->presentacion_estado, [EstadoPresentacionCobro::Presentada, EstadoPresentacionCobro::Recibida], true) => 'ya se presentó',
            $doc->presentacion_estado === EstadoPresentacionCobro::Preparada,
            $claves->has(IdentidadPpq::normalizar($doc->numero_control)) => 'ya está en un PPQ',
            default => null,
        };
    }
}
