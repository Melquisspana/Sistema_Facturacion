<?php

namespace App\Services\Cobros;

use App\Console\Commands\PpqLiberarLotesBorradosCommand;
use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\IdentidadPpq;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Al borrar un PPQ, sus CCF vuelven a «por presentar» en el Seguimiento: el estado «En PPQ»
 * es una columna guardada (`presentacion_estado`) que el lote puso al crearse y que nadie
 * deshacía (issue #8).
 *
 * Solo se libera lo que estaba ahí POR ESTE LOTE. No se toca, y se informa:
 *  · lo pagado o con pago registrado;
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
            $items = $lote->items()->where('tipo_dte', '03')->get(['dte_id', 'numero_control']);
            $claves = $items->pluck('numero_control')->map(fn ($n) => IdentidadPpq::normalizar($n))->filter()->values();

            $documentos = CobroDocumento::where('tipo_dte', '03')
                ->when($lote->cliente_id !== null, fn ($q) => $q->deCliente($lote->cliente_id))
                ->where(fn ($q) => $q->whereIn('dte_id', $items->pluck('dte_id')->filter()->values())
                    ->orWhereIn('numero_control_norm', $claves))
                ->orderBy('id')
                ->when($aplicar, fn ($q) => $q->lockForUpdate())
                ->get();
            $documentos->load([
                'solicitud:id,referencia',
                'eventos' => fn ($q) => $q->where('tipo', TipoEventoCobro::Recibido->value),
            ]);

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

    /** @param  Collection<string, int>  $ocupadas */
    private function motivoParaConservar(CobroDocumento $doc, Collection $ocupadas): ?string
    {
        if ($doc->pago_estado !== EstadoPagoCobro::Pendiente) {
            return 'pagado o con pago registrado';
        }
        if ($doc->cobro_solicitud_id !== null) {
            return 'va en la solicitud '.($doc->solicitud?->referencia ?? '#'.$doc->cobro_solicitud_id);
        }
        if (($otro = $ocupadas->get($doc->numero_control_norm)
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
