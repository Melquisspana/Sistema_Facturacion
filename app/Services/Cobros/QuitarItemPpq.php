<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
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
 * CCF que el cliente no aceptó: sale de ese quedan y vuelve a «por presentar», para entrar en
 * otro PPQ ({@see SoltarCcfDelQuedan}). Antes se quedaba «presentado» colgando del quedan
 * viejo y no entraba en ningún lote. Lo que el cliente ya recibió queda como está. Uno
 * agregado a mano después de armar ese quedan se conserva «en la solicitud», porque ese
 * quedan no salió de este lote.
 *
 * Si va en un quedan armado en Cobros, después de que el renglón entró al lote, que todavía no
 * tiene la presentación registrada, no se quita: el renglón sigue en el lote y se avisa que
 * primero se registre la presentación.
 *
 * Quitar una NC no toca su CCF. Quitar un CCF no saca sus NC: se informan en `notas`.
 */
class QuitarItemPpq
{
    /**
     * `quedan`: la referencia del quedan del que salió el CCF, para decirlo en el aviso.
     * `quedan_generado`: la del quedan sin presentación registrada que no dejó quitarlo.
     *
     * @return array{quitado: bool, no_editable: bool, conservado: ?PpqItem, devueltos: Collection<int, CobroDocumento>, conservados: Collection<int, array{documento: CobroDocumento, motivo: string}>, sin_cambio: Collection<int, CobroDocumento>, notas: Collection<int, PpqItem>, quedan: ?string, quedan_generado: ?string}
     */
    public function quitar(PpqLote $lote, PpqItem $item, ?User $usuario = null): array
    {
        return DB::transaction(function () use ($lote, $item, $usuario) {
            $lote = PpqLote::whereKey($lote->id)->lockForUpdate()->firstOrFail();
            $resultado = ['quitado' => false, 'no_editable' => false, 'conservado' => null, 'devueltos' => collect(),
                'conservados' => collect(), 'sin_cambio' => collect(), 'notas' => collect(), 'quedan' => null, 'quedan_generado' => null];
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

            // Se decide ANTES de sacar el renglón: si el CCF no puede salir todavía, el renglón
            // tiene que seguir en el lote para poder quitarlo después. El quedan se bloquea antes
            // que sus CCF, en el orden del acuse y de la presentación, y se decide con su estado
            // releído bajo candado ({@see SoltarCcfDelQuedan::bloquearQuedanes()}). Leído sin
            // candado, un acuse que entraba por correo a la vez lo dejaba ver «presentado»: el
            // CCF se soltaba y el acuse igual le dejaba su evento «recibido».
            $liberador = app(LiberarCcfDeLoteBorrado::class);
            $salida = app(SoltarCcfDelQuedan::class);
            $documentos = collect();
            $quedanes = collect();
            if (! $item->esNc()) {
                $quedanes = $salida->bloquearQuedanes($liberador->documentosDe($lote, collect([$item]), bloquear: false));
                $documentos = $liberador->documentosDe($lote, collect([$item]));
            }
            // Un quedan armado en Cobros, con este renglón ya en el lote, que todavía no tiene la
            // presentación registrada: si el renglón saliera, el CCF quedaría en ese quedan y, al
            // registrarla, presentado sin lote del cual quitarlo. Primero se registra la
            // presentación. Si el renglón llegó después, ese quedan no salió de este lote: el
            // renglón sale y el CCF sigue en el quedan ({@see SoltarCcfDelQuedan::quedanSinRegistrar()}).
            $generado = $documentos->map(fn (CobroDocumento $doc) => $salida->quedanSinRegistrar($item, $doc, $quedanes))->filter()->first();
            if ($generado !== null) {
                $resultado['quedan_generado'] = $generado->referencia;

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

            $ocupadas = app(ElegibilidadPpqSeguimiento::class)->claves($lote->id);
            foreach ($documentos as $doc) {
                if ($doc->presentacion_estado === EstadoPresentacionCobro::SinPresentar) {
                    $resultado['sin_cambio']->push($doc);

                    continue;
                }
                // Vuelve lo que este lote dejó «en PPQ» y lo de un quedan presentado sin acuse
                // que salió de este lote ({@see SoltarCcfDelQuedan::quedanQueSuelta()}). Uno
                // agregado a mano después, o en el mismo segundo, no lo suelta. Lo demás
                // presentado queda como está. Si el CCF cambió de quedan entre las dos lecturas,
                // no hay candado sobre el nuevo y queda como está.
                $solicitud = $doc->cobro_solicitud_id !== null ? $quedanes->get($doc->cobro_solicitud_id) : null;
                $enQuedanPresentado = $doc->presentacion_estado === EstadoPresentacionCobro::Presentada
                    && $solicitud?->estado === EstadoSolicitudCobro::Presentada;
                $quedan = $salida->quedanQueSuelta($item, $doc, $quedanes);
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

                if ($quedan !== null) {
                    $salida->soltar($doc, $quedan, $usuario, 'un CCF salió del quedan presentado al quitarlo del PPQ', ['ppq_lote_id' => $lote->id]);
                    $resultado['quedan'] = $quedan->referencia;
                } else {
                    $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value])->save();
                }
                CobroEvento::firstOrCreate(
                    ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'referencia_linea' => "ppq-{$lote->id}-quitado"],
                    ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id,
                        'datos' => ['ppq_lote_id' => $lote->id] + ($quedan !== null ? ['cobro_solicitud_id' => $quedan->id] : []),
                        'detalle' => "Se quitó del PPQ {$lote->referencia}"
                            .($quedan !== null ? " y salió del quedan presentado {$quedan->referencia}" : '').': vuelve a por presentar.'],
                );
                $resultado['devueltos']->push($doc);
            }

            return $resultado;
        });
    }
}
