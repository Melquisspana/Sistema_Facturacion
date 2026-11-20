<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * El CCF que el cliente no tomó sale de su lote PPQ y también del quedan presentado desde
 * Cobros del que cuelga, para poder ir en otro PPQ y en otro quedan. Es la misma salida para
 * el «quitar» ({@see QuitarItemPpq}), el reporte del caso ({@see ReporteCasoCalleja}) y el
 * reenvío por TXT ({@see ReenviarPendientesTxt}).
 *
 * Sale solo de un quedan presentado y todavía sin acuse, y solo si el renglón ya estaba en el
 * lote cuando se armó ese quedan: no hay otro vínculo entre lote y quedan. Si nacieron en el
 * mismo segundo no se sabe cuál fue primero (las horas no guardan fracciones) y, ante la
 * duda, se queda. El quedan conserva sus renglones como constancia de lo que se presentó ese
 * día y su bitácora dice qué CCF salió.
 */
class SoltarCcfDelQuedan
{
    /**
     * Bloquea y relee los quedanes de estos CCF. Va ANTES de bloquear o tocar los CCF, en el
     * orden del acuse y de la presentación ({@see SolicitudCobroService::registrarRecibido()}):
     * así se decide con el estado de ahora y no se cruza con un acuse que entra por correo a
     * la vez. Después, quien llama relee los CCF con candado.
     *
     * @param  Collection<int, CobroDocumento>  $documentos  leídos sin candado
     * @return Collection<int, CobroSolicitud> los quedanes releídos, por id
     */
    public function bloquearQuedanes(Collection $documentos): Collection
    {
        return CobroSolicitud::whereKey($documentos->pluck('cobro_solicitud_id')->filter()->unique()->values())
            ->lockForUpdate()->get()->keyBy('id');
    }

    /** El quedan del que sale este CCF al sacar su renglón del lote, o null si no cuelga de uno o no le toca salir. */
    public function quedanQueSuelta(PpqItem $item, CobroDocumento $doc, Collection $quedanes): ?CobroSolicitud
    {
        $quedan = $doc->cobro_solicitud_id !== null ? $quedanes->get($doc->cobro_solicitud_id) : null;

        return $doc->presentacion_estado === EstadoPresentacionCobro::Presentada
            && $quedan?->estado === EstadoSolicitudCobro::Presentada
            && $item->created_at?->lt($quedan->created_at) ? $quedan : null;
    }

    /**
     * El quedan armado en Cobros, todavía sin la presentación registrada, del que este CCF
     * saldría si ya la tuviera: el renglón estaba en el lote cuando se armó. Mientras tanto el
     * renglón no sale del lote, para poder soltar el CCF después; si saliera, al registrar la
     * presentación el CCF quedaría presentado sin lote del cual quitarlo. Null si el renglón
     * llegó después o en el mismo segundo: ese quedan no salió de este lote y sacar el renglón
     * no cambia nada de él.
     */
    public function quedanSinRegistrar(PpqItem $item, CobroDocumento $doc, Collection $quedanes): ?CobroSolicitud
    {
        $quedan = $doc->cobro_solicitud_id !== null ? $quedanes->get($doc->cobro_solicitud_id) : null;

        return $quedan?->estado === EstadoSolicitudCobro::Generada && $item->created_at?->lt($quedan->created_at) ? $quedan : null;
    }

    /**
     * El primero de esos quedanes entre los CCF del lote ({@see quedanSinRegistrar()}), para el
     * borrado del lote y su botón. Con `$bloquear`, los quedanes se releen con candado antes que
     * cualquier CCF, como en {@see bloquearQuedanes()}.
     */
    public function quedanSinRegistrarDelLote(PpqLote $lote, bool $bloquear = true): ?CobroSolicitud
    {
        $ccf = $lote->items()->where('tipo_dte', '03')->get();
        $documentos = app(LiberarCcfDeLoteBorrado::class)->documentosDe($lote, $ccf, bloquear: false);
        $quedanes = CobroSolicitud::whereKey($documentos->pluck('cobro_solicitud_id')->filter()->unique()->values())
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $reporte = app(ReporteCasoCalleja::class);

        return $ccf->flatMap(fn (PpqItem $item) => $documentos->filter(fn (CobroDocumento $doc) => $reporte->mismoDocumento($item, $doc))
            ->map(fn (CobroDocumento $doc) => $this->quedanSinRegistrar($item, $doc, $quedanes)))->filter()->first();
    }

    /**
     * Por qué este CCF no puede volver a «por presentar» al salir del lote: cuelga de un quedan
     * del que no sale, y entonces tampoco entraría en otro. Null si no cuelga de ninguno o si
     * sale de él ({@see quedanQueSuelta()}).
     */
    public function motivoParaRetener(PpqItem $item, CobroDocumento $doc, Collection $quedanes): ?string
    {
        if ($doc->cobro_solicitud_id === null || $this->quedanQueSuelta($item, $doc, $quedanes) !== null) {
            return null;
        }
        $quedan = $quedanes->get($doc->cobro_solicitud_id);
        $referencia = 'el quedan '.($quedan?->referencia ?? '#'.$doc->cobro_solicitud_id);

        return match ($quedan?->estado) {
            EstadoSolicitudCobro::Generada => "va en {$referencia}, que todavía no tiene la presentación registrada",
            EstadoSolicitudCobro::Recibida => "va en {$referencia}, que ya tiene el acuse del cliente",
            default => "va en {$referencia}, que no se armó con este PPQ",
        };
    }

    /**
     * Para el aviso de «quitar» en la ficha del lote: de qué quedan saldría el CCF de cada uno
     * de estos renglones, con la misma regla que el «quitar» ({@see quedanQueSuelta()}). Solo
     * lee, sin candados.
     *
     * @param  Collection<int, PpqItem>  $items
     * @return array<int, string> id del renglón => referencia del quedan
     */
    public function quedanesAlQuitar(PpqLote $lote, Collection $items): array
    {
        $ccf = $items->reject(fn (PpqItem $item) => $item->esNc())->values();
        if ($ccf->isEmpty()) {
            return [];
        }
        $documentos = app(LiberarCcfDeLoteBorrado::class)->documentosDe($lote, $ccf, bloquear: false);
        $quedanes = CobroSolicitud::whereKey($documentos->pluck('cobro_solicitud_id')->filter()->unique()->values())->get()->keyBy('id');
        $reporte = app(ReporteCasoCalleja::class);
        $avisos = [];
        foreach ($ccf as $item) {
            $quedan = $documentos->filter(fn (CobroDocumento $doc) => $reporte->mismoDocumento($item, $doc))
                ->map(fn (CobroDocumento $doc) => $this->quedanQueSuelta($item, $doc, $quedanes))->filter()->first();
            if ($quedan !== null) {
                $avisos[$item->id] = $quedan->referencia;
            }
        }

        return $avisos;
    }

    /** Lo saca del quedan, lo devuelve a «por presentar» y lo anota en la bitácora del quedan. */
    public function soltar(CobroDocumento $doc, CobroSolicitud $quedan, ?User $usuario, string $descripcion, array $propiedades = []): void
    {
        $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value, 'cobro_solicitud_id' => null])->save();
        activity('cobros_solicitud')->performedOn($quedan)->causedBy($usuario)
            ->withProperties(['cobro_documento_id' => $doc->id, 'numero_control' => $doc->numero_control] + $propiedades)
            ->log($descripcion);
    }
}
