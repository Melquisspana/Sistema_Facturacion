<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «quitar» un renglón en la ficha de un lote editable. Sale con constancia y con el resguardo
 * de {@see RetirarItemsPpq} (observación y bitácora; lo conciliado o con historia no sale).
 *
 * Si es un CCF que estaba «en PPQ», vuelve a «por presentar» con la regla del borrado del lote
 * ({@see LiberarCcfDeLoteBorrado::motivoParaConservar()}). Antes solo se borraba el renglón y el
 * CCF quedaba «en PPQ» sin estar en ninguno: fuera de la bandeja y sin casilla.
 *
 * Quitar una NC no toca su CCF. Quitar un CCF no saca sus NC: se informan en `notas`.
 */
class QuitarItemPpq
{
    /**
     * @return array{quitado: bool, conservado: ?PpqItem, devueltos: Collection<int, CobroDocumento>, conservados: Collection<int, array{documento: CobroDocumento, motivo: string}>, sin_cambio: Collection<int, CobroDocumento>, notas: Collection<int, PpqItem>}
     */
    public function quitar(PpqLote $lote, PpqItem $item, ?User $usuario = null): array
    {
        return DB::transaction(function () use ($lote, $item, $usuario) {
            $lote = PpqLote::whereKey($lote->id)->lockForUpdate()->firstOrFail();
            $items = $lote->items()->with('dte')->lockForUpdate()->get();
            $item = $items->firstWhere('id', $item->id);
            $resultado = ['quitado' => false, 'conservado' => null, 'devueltos' => collect(), 'conservados' => collect(),
                'sin_cambio' => collect(), 'notas' => collect()];
            if ($item === null) {
                return $resultado;
            }

            $retiro = app(RetirarItemsPpq::class)->retirar($lote, collect([$item]),
                'Quitado a mano el '.today()->format('d/m/Y').':', 'ppq.item_quitado', [], $usuario);
            if ($retiro['items_quitados']->isEmpty()) {
                $resultado['conservado'] = $retiro['conservados']->first();

                return $resultado;
            }
            $resultado['quitado'] = true;
            if ($item->esNc()) {
                return $resultado;
            }

            $reporte = app(ReporteCasoCalleja::class);
            $resultado['notas'] = $items->filter(fn (PpqItem $nc) => $nc->esNc() && $reporte->ccfDeNota($nc, $items)?->id === $item->id)->values();

            $liberador = app(LiberarCcfDeLoteBorrado::class);
            $ocupadas = app(ElegibilidadPpqSeguimiento::class)->claves($lote->id);
            foreach ($liberador->documentosDe($lote, collect([$item])) as $doc) {
                if ($doc->presentacion_estado === EstadoPresentacionCobro::SinPresentar) {
                    $resultado['sin_cambio']->push($doc);

                    continue;
                }
                // Solo vuelve lo que este lote dejó «en PPQ»; lo presentado queda como está.
                $motivo = $doc->presentacion_estado === EstadoPresentacionCobro::Preparada
                    ? $liberador->motivoParaConservar($doc, $ocupadas)
                    : 'ya figura como presentado';
                if ($motivo !== null) {
                    $resultado['conservados']->push(['documento' => $doc, 'motivo' => $motivo]);

                    continue;
                }

                $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value])->save();
                CobroEvento::firstOrCreate(
                    ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'referencia_linea' => "ppq-{$lote->id}-quitado"],
                    ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id, 'datos' => ['ppq_lote_id' => $lote->id],
                        'detalle' => "Se quitó del PPQ {$lote->referencia}: vuelve a por presentar."],
                );
                $resultado['devueltos']->push($doc);
            }

            return $resultado;
        });
    }
}
