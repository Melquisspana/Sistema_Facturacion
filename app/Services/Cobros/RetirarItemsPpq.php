<?php

namespace App\Services\Cobros;

use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saca items de un lote PPQ dejando constancia en sus observaciones y en la bitácora. Lo
 * conciliado o con historia de conciliación se conserva. Lo comparten el reporte del caso
 * de Calleja y la devolución por TXT.
 */
class RetirarItemsPpq
{
    /** Retira sin perder conciliaciones ni su historia. */
    public function retirar(PpqLote $lote, Collection $documentos, string $encabezado, string $actividadNombre, array $propiedades, ?User $usuario = null): array
    {
        return DB::transaction(function () use ($lote, $encabezado, $actividadNombre, $propiedades, $documentos, $usuario) {
            $lote = PpqLote::whereKey($lote->id)->lockForUpdate()->firstOrFail();
            $items = $lote->items()->lockForUpdate()->get()->filter(fn ($item) => $documentos->contains(
                fn ($doc) => $doc instanceof PpqItem ? $doc->id === $item->id : app(ReporteCasoCalleja::class)->mismoDocumento($item, $doc)
            ));
            $conHistoria = DB::table('ppq_conciliacion_movimientos')->whereIn('ppq_item_id', $items->pluck('id'))->pluck('ppq_item_id')->flip();
            $conservados = $items->filter(fn ($item) => $item->estaConciliado() || $conHistoria->has($item->id))->values();
            $quitados = $items->reject(fn ($item) => $conservados->contains('id', $item->id))->values();
            if ($quitados->isNotEmpty()) {
                $lineas = $quitados->map(function ($item) {
                    $monto = $item->montoDteConSigno();

                    return '· '.($item->esNc() ? 'NC ' : 'CCF ').$item->correlativoNumero().' · '.($monto < 0 ? '-' : '').'$'.number_format(abs($monto), 2).' · '.($item->fecha_documento?->format('d/m/Y') ?? '—');
                });
                $lote->update(['observaciones' => trim((string) $lote->observaciones)."\n".$encabezado."\n".$lineas->implode("\n")]);
                $actividad = activity()->performedOn($lote)->withProperties($propiedades + ['items' => $quitados->map->getAttributes()->all()]);
                if ($usuario) {
                    $actividad->causedBy($usuario);
                }
                $actividad->log($actividadNombre);
                foreach ($quitados as $item) {
                    $item->delete();
                }
            }

            return ['items_quitados' => $quitados, 'conservados' => $conservados];
        });
    }
}
