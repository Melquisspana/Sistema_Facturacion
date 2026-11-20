<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «quitar» un renglón en la ficha de un lote editable (se revisa con el lote bloqueado; si ya
 * no lo es, no se toca nada y vuelve `no_editable`). Sale con constancia y con el resguardo
 * de {@see RetirarItemsPpq} (observación y bitácora; lo conciliado o con historia no sale).
 *
 * Si es un CCF que estaba «en PPQ», vuelve a «por presentar» con la regla del borrado del lote
 * ({@see LiberarCcfDeLoteBorrado::motivoParaConservar()}). Antes solo se borraba el renglón y el
 * CCF quedaba «en PPQ» sin estar en ninguno: fuera de la bandeja y sin casilla.
 *
 * Si va en un quedan ya presentado desde Cobros y el cliente todavía no acusó recibo, es el
 * CCF que el cliente no aceptó: sale de ese quedan (sus renglones quedan como constancia de lo
 * que se presentó ese día) y vuelve a «por presentar», para entrar en otro PPQ. Antes se
 * quedaba «presentado» colgando del quedan viejo y no entraba en ningún lote. Lo que el cliente
 * ya recibió queda como está. Solo sale si el renglón ya estaba en este lote cuando se armó ese
 * quedan (no hay otro vínculo entre lote y quedan): uno agregado a mano después se conserva
 * «en la solicitud», porque ese quedan no salió de este lote.
 *
 * Quitar una NC no toca su CCF. Quitar un CCF no saca sus NC: se informan en `notas`.
 */
class QuitarItemPpq
{
    /**
     * `quedan`: la referencia del quedan del que salió el CCF, para decirlo en el aviso.
     *
     * @return array{quitado: bool, no_editable: bool, conservado: ?PpqItem, devueltos: Collection<int, CobroDocumento>, conservados: Collection<int, array{documento: CobroDocumento, motivo: string}>, sin_cambio: Collection<int, CobroDocumento>, notas: Collection<int, PpqItem>, quedan: ?string}
     */
    public function quitar(PpqLote $lote, PpqItem $item, ?User $usuario = null): array
    {
        return DB::transaction(function () use ($lote, $item, $usuario) {
            $lote = PpqLote::whereKey($lote->id)->lockForUpdate()->firstOrFail();
            $resultado = ['quitado' => false, 'no_editable' => false, 'conservado' => null, 'devueltos' => collect(),
                'conservados' => collect(), 'sin_cambio' => collect(), 'notas' => collect(), 'quedan' => null];
            // Se revisa con el candado puesto: otro pudo marcarlo presentado mientras este esperaba.
            if (! $lote->esEditable()) {
                $resultado['no_editable'] = true;

                return $resultado;
            }
            $items = $lote->items()->with('dte')->lockForUpdate()->get();
            $item = $items->firstWhere('id', $item->id);
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
            // El quedan se bloquea ANTES que sus CCF, en el orden del acuse y de la presentación
            // ({@see SolicitudCobroService::registrarRecibido()}): solicitud y después documentos.
            // Y se decide con su estado releído bajo candado. Leído sin candado, un acuse que
            // entraba por correo a la vez lo dejaba ver «presentado»: el CCF se soltaba y el
            // acuse igual le dejaba su evento «recibido».
            $idsQuedan = $liberador->documentosDe($lote, collect([$item]), bloquear: false)
                ->pluck('cobro_solicitud_id')->filter()->unique()->values();
            $quedanes = CobroSolicitud::whereKey($idsQuedan)->lockForUpdate()->get()->keyBy('id');
            foreach ($liberador->documentosDe($lote, collect([$item])) as $doc) {
                if ($doc->presentacion_estado === EstadoPresentacionCobro::SinPresentar) {
                    $resultado['sin_cambio']->push($doc);

                    continue;
                }
                // Vuelve lo que este lote dejó «en PPQ» y lo de un quedan presentado sin acuse
                // que salió de este lote: el renglón ya estaba cuando se armó el quedan. Uno
                // agregado a mano después no lo suelta (el quedan no es de este lote), ni uno
                // del mismo segundo: las horas no guardan fracciones y, ante la duda, se queda. Lo demás
                // presentado queda como está. Si el CCF cambió de quedan entre las dos lecturas,
                // no hay candado sobre el nuevo y queda como está.
                $solicitud = $doc->cobro_solicitud_id !== null ? $quedanes->get($doc->cobro_solicitud_id) : null;
                $enQuedanPresentado = $doc->presentacion_estado === EstadoPresentacionCobro::Presentada
                    && $solicitud?->estado === EstadoSolicitudCobro::Presentada;
                $quedan = $enQuedanPresentado && $item->created_at?->lt($solicitud->created_at) ? $solicitud : null;
                $motivo = match (true) {
                    $doc->presentacion_estado === EstadoPresentacionCobro::Preparada => $liberador->motivoParaConservar($doc, $ocupadas),
                    $quedan !== null => $liberador->motivoParaConservar($doc, $ocupadas, sueltaDelQuedan: true),
                    $enQuedanPresentado => $liberador->motivoParaConservar($doc, $ocupadas), // «va en la solicitud …»
                    $solicitud?->estado === EstadoSolicitudCobro::Recibida => "el cliente ya dio el acuse del quedan {$solicitud->referencia}",
                    default => 'ya figura como presentado',
                };
                if ($motivo !== null) {
                    $resultado['conservados']->push(['documento' => $doc, 'motivo' => $motivo]);

                    continue;
                }

                $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value]
                    + ($quedan !== null ? ['cobro_solicitud_id' => null] : []))->save();
                CobroEvento::firstOrCreate(
                    ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'referencia_linea' => "ppq-{$lote->id}-quitado"],
                    ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id,
                        'datos' => ['ppq_lote_id' => $lote->id] + ($quedan !== null ? ['cobro_solicitud_id' => $quedan->id] : []),
                        'detalle' => "Se quitó del PPQ {$lote->referencia}"
                            .($quedan !== null ? " y salió del quedan presentado {$quedan->referencia}" : '').': vuelve a por presentar.'],
                );
                if ($quedan !== null) {
                    activity('cobros_solicitud')->performedOn($quedan)->causedBy($usuario)
                        ->withProperties(['cobro_documento_id' => $doc->id, 'numero_control' => $doc->numero_control, 'ppq_lote_id' => $lote->id])
                        ->log('un CCF salió del quedan presentado al quitarlo del PPQ');
                    $resultado['quedan'] = $quedan->referencia;
                }
                $resultado['devueltos']->push($doc);
            }

            return $resultado;
        });
    }
}
