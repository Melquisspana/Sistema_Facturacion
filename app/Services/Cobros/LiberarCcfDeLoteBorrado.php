<?php

namespace App\Services\Cobros;

use App\Console\Commands\PpqLiberarLotesBorradosCommand;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\EjercicioControl;
use App\Support\IdentidadPpq;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Al borrar un PPQ, sus CCF vuelven a «por presentar» en el Seguimiento: el estado «En PPQ»
 * es una columna guardada (`presentacion_estado`) que el lote puso al crearse y que nadie
 * deshacía (issue #8).
 *
 * Solo se libera lo que estaba ahí POR ESTE LOTE. No se toca, y se informa:
 *  · lo pagado o con pago registrado, salvo lo pagado de menos que el TXT devolvió para el
 *    próximo PPQ;
 *  · lo que va en una solicitud de cobro (su estado viene de la solicitud);
 *  · lo que sigue en otro lote vigente (p. ej. el duplicado de un doble clic);
 *  · lo que Calleja registró en un caso: es una presentación real, con evidencia.
 *
 * Lo usan el borrado desde la ficha del lote y el comando de reparación de lotes ya
 * borrados ({@see PpqLiberarLotesBorradosCommand}). Es idempotente:
 * lo que ya está por presentar queda en `sin_cambio`.
 */
class LiberarCcfDeLoteBorrado
{
    /**
     * @return array{liberados: Collection<int, CobroDocumento>, conservados: Collection<int, array{documento: CobroDocumento, motivo: string}>, sin_cambio: Collection<int, CobroDocumento>}
     */
    public function liberar(PpqLote $lote, ?User $usuario = null, bool $aplicar = true): array
    {
        return DB::transaction(function () use ($lote, $usuario, $aplicar) {
            $items = $lote->items()->where('tipo_dte', '03')->get(['dte_id', 'numero_control', 'ejercicio_control']);
            $documentos = $this->documentosDe($lote, $items, $aplicar);

            $ocupadas = app(ElegibilidadPpqSeguimiento::class)->claves($lote->id);
            $resultado = ['liberados' => collect(), 'conservados' => collect(), 'sin_cambio' => collect()];

            foreach ($documentos as $doc) {
                if ($doc->presentacion_estado === EstadoPresentacionCobro::SinPresentar) {
                    $resultado['sin_cambio']->push($doc);

                    continue;
                }

                $motivo = $this->motivoParaConservar($doc, $ocupadas);
                if ($motivo !== null) {
                    $resultado['conservados']->push(['documento' => $doc, 'motivo' => $motivo]);

                    continue;
                }

                if ($aplicar) {
                    $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value])->save();
                    CobroEvento::firstOrCreate(
                        ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'referencia_linea' => "ppq-{$lote->id}-borrado"],
                        ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id,
                            'detalle' => "Se borró el PPQ {$lote->referencia}: vuelve a por presentar."],
                    );
                }
                $resultado['liberados']->push($doc);
            }

            return $resultado;
        });
    }

    /**
     * Los CCF del Seguimiento que corresponden a estos items del lote (por dte_id o por número
     * y ejercicio), con lo que necesita {@see motivoParaConservar()}.
     *
     * @param  Collection<int, PpqItem>  $items  items CCF del lote
     * @return Collection<int, CobroDocumento>
     */
    public function documentosDe(PpqLote $lote, Collection $items, bool $bloquear = true): Collection
    {
        $claves = $items->pluck('numero_control')->map(fn ($n) => IdentidadPpq::normalizar($n))->filter()->values();

        $documentos = CobroDocumento::where('tipo_dte', '03')
            ->when($lote->cliente_id !== null, fn ($q) => $q->deCliente($lote->cliente_id))
            ->where(fn ($q) => $q->whereIn('dte_id', $items->pluck('dte_id')->filter()->values())
                ->orWhereIn('numero_control_norm', $claves))
            ->orderBy('id')
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->get()->filter(fn ($d) => $items->contains(fn ($i) => ($i->dte_id !== null && $i->dte_id === $d->dte_id)
                || EjercicioControl::llave($i->numero_control, $i->ejercicio_control) === EjercicioControl::llave($d->numero_control, $d->ejercicio_control)));

        return $documentos->load([
            'solicitud:id,referencia,estado',
            'eventos' => fn ($q) => $q->where('tipo', TipoEventoCobro::Recibido->value),
        ]);
    }

    /**
     * Por qué un CCF que salió de un lote NO vuelve a «por presentar», o null si vuelve. La
     * comparten el borrado del lote y el «quitar» de un renglón ({@see QuitarItemPpq}).
     *
     * @param  Collection<string, int>  $ocupadas  las de {@see ElegibilidadPpqSeguimiento::claves()}, sin este lote
     * @param  bool  $sueltaDelQuedan  el «quitar» de un CCF que el cliente no aceptó en un quedan presentado: estar en esa solicitud no lo retiene
     */
    public function motivoParaConservar(CobroDocumento $doc, Collection $ocupadas, bool $sueltaDelQuedan = false): ?string
    {
        // Lo pagado de menos que el TXT devolvió para el próximo PPQ sí vuelve, con la misma
        // regla que lo dejó entrar en el lote ({@see ElegibilidadPpqSeguimiento::pagoAdmitePpq()}).
        if (! app(ElegibilidadPpqSeguimiento::class)->pagoAdmitePpq($doc)) {
            return 'pagado o con pago registrado';
        }
        if ($doc->cobro_solicitud_id !== null && ! $sueltaDelQuedan) {
            return 'va en la solicitud '.($doc->solicitud?->referencia ?? '#'.$doc->cobro_solicitud_id);
        }
        if (($otro = $ocupadas->get(EjercicioControl::llave($doc->numero_control_norm, $doc->ejercicio_control))
            ?? ($doc->dte_id !== null ? $ocupadas->get('dte:'.$doc->dte_id) : null)) !== null) {
            return "sigue en el PPQ #{$otro}, que no está borrado";
        }
        $recibido = $doc->eventos->first();
        if ($recibido !== null && in_array($doc->presentacion_estado, [EstadoPresentacionCobro::Presentada, EstadoPresentacionCobro::Recibida], true)) {
            $caso = $recibido->datos['caso'] ?? null;

            return 'Calleja ya lo registró'.($caso ? " (caso {$caso})" : '');
        }

        return null;
    }
}
