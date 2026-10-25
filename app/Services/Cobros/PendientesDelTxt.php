<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\EstadoDte;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Support\Dinero;
use App\Support\IdentidadPpq;
use Illuminate\Support\Collection;

/**
 * Lo que el TXT del último PPQ NO pagó, de los lotes que ese TXT concilió: CCF que no
 * vinieron, CCF pagados de menos (posible devolución AC04) y NC que Calleja no descontó.
 * Un TXT por PPQ (regla del usuario): lo que no vino en él, de ese lote, no se pagó.
 *
 * Solo lee. Lo usa la pantalla del TXT y lo vuelve a usar {@see ReenviarPendientesTxt}
 * como única regla de qué se puede devolver.
 */
class PendientesDelTxt
{
    /**
     * @param  Collection<int, PpqLote>  $lotes
     * @return array{no_pagados: array<int, array<string, mixed>>, pagados_de_menos: array<int, array<string, mixed>>, nc_no_descontadas: array<int, array<string, mixed>>, no_aplican: array<int, array<string, mixed>>}
     */
    public function analizar(Cliente $cliente, Collection $lotes): array
    {
        $resultado = ['no_pagados' => [], 'pagados_de_menos' => [], 'nc_no_descontadas' => [], 'no_aplican' => []];
        $motivos = app(NoAplicaCobro::class)->motivosPorDte($cliente);
        $lotes = $lotes->unique('id')->filter(fn ($lote) => (int) $lote->cliente_id === (int) $cliente->id);
        $itemsPorLote = $lotes->mapWithKeys(fn ($lote) => [$lote->id => $lote->items()->with('dte')->orderBy('id')->get()]);
        $items = $itemsPorLote->flatten(1);
        // Solo los documentos de estos lotes (por DTE, por número o como CCF de sus NC).
        $documentos = CobroDocumento::deCliente($cliente->id)->with('albaran')
            ->where(fn ($q) => $q->whereIn('dte_id', $items->pluck('dte_id')->merge($items->pluck('dte.dte_relacionado_id'))->filter()->all())
                ->orWhereIn('numero_control_norm', IdentidadPpq::claves($items->pluck('numero_control'))))
            ->get();
        foreach ($lotes as $lote) {
            foreach ($itemsPorLote[$lote->id] as $item) {
                $doc = $item->dte_id ? $documentos->firstWhere('dte_id', $item->dte_id) : null;
                if (! $doc) {
                    $clave = IdentidadPpq::normalizar($item->numero_control);
                    $coinciden = $clave === null ? collect() : $documentos->where('numero_control_norm', $clave);
                    $doc = $coinciden->count() === 1 ? $coinciden->first() : null;
                }
                $dteId = $item->dte_id ?? $doc?->dte_id;
                if (! $dteId && $item->tipo_dte === '05') {
                    $clave = IdentidadPpq::normalizar($item->numero_control);
                    $notas = Dte::where('cliente_id', $cliente->id)->where('tipo_dte', '05')->get()
                        ->filter(fn ($nota) => $clave !== null && IdentidadPpq::normalizar($nota->numero_control) === $clave);
                    $dteId = $notas->count() === 1 ? $notas->first()->id : null;
                }
                if (isset($motivos[$dteId])) {
                    $resultado['no_aplican'][] = ['item' => $item, 'lote' => $lote, 'documento' => $doc, 'motivo' => $motivos[$dteId]];

                    continue;
                }
                if ($doc?->tienePagosEnRevision()) {
                    continue;
                }
                $entrada = ['item' => $item, 'lote' => $lote, 'documento' => $doc];
                if ($item->tipo_dte === '03' && $doc) {
                    if (! $item->estaConciliado() && $doc->pago_estado === EstadoPagoCobro::Pendiente) {
                        $resultado['no_pagados'][] = $entrada;
                    } elseif (in_array($doc->pago_estado, [EstadoPagoCobro::Parcial, EstadoPagoCobro::Diferencia], true) && Dinero::comparar($doc->saldo(), '0') > 0) {
                        $ac04 = $doc->dte_id ? Dte::where('tipo_dte', '05')->where('dte_relacionado_id', $doc->dte_id)
                            ->with('albaran')->get()->pluck('albaran')->filter(fn ($a) => $a?->tipo_codigo === 'AC04')->pluck('numero_canonico') : collect();
                        if ($oc = $doc->albaran?->numero_orden_compra) {
                            $ac04 = $ac04->merge(PpqAlbaran::where('tipo_codigo', 'AC04')->where('numero_orden_compra', $oc)->pluck('numero_albaran'));
                        }
                        $resultado['pagados_de_menos'][] = $entrada + ['esperado' => $doc->facturadoEfectivo(), 'pagado' => $doc->monto_pagado,
                            'diferencia' => $doc->saldo(), 'ac04' => $ac04->filter()->unique()->values()->all()];
                    }
                } elseif ($item->tipo_dte === '05' && ! $item->estaConciliado()) {
                    $nc = Dte::sinNcProntoPago()->where('cliente_id', $cliente->id)->where('tipo_dte', '05')
                        ->where('estado', '!=', EstadoDte::Invalidado->value)
                        ->when($item->dte_id, fn ($q) => $q->whereKey($item->dte_id))->get();
                    if (! $item->dte_id) {
                        $clave = IdentidadPpq::normalizar($item->numero_control);
                        $nc = $nc->filter(fn ($dte) => $clave !== null && IdentidadPpq::normalizar($dte->numero_control) === $clave);
                    }
                    if ($nc->count() === 1) {
                        $ccf = $nc->first()->dte_relacionado_id ? $documentos->firstWhere('dte_id', $nc->first()->dte_relacionado_id) : null;
                        if (! $ccf?->tienePagosEnRevision()) {
                            $item->setRelation('dte', $nc->first());
                            $resultado['nc_no_descontadas'][] = $entrada;
                        }
                    }
                }
            }
        }

        return $resultado;
    }
}
