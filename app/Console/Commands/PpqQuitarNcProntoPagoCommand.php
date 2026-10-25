<?php

namespace App\Console\Commands;

use App\Models\Dte;
use App\Models\PpqLote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Quita las NC de pronto pago de PPQ vigentes, conservando las conciliadas. */
class PpqQuitarNcProntoPagoCommand extends Command
{
    protected $signature = 'ppq:quitar-nc-pronto-pago
        {--dry-run : Solo muestra lo que haría, sin cambiar nada}';

    protected $description = 'Quita del PPQ las NC de pronto pago que no van en el quedan';

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');
        $quitadas = [];
        $conservadas = [];
        $candidatos = fn ($q) => $q->where('tipo_dte', '05')->whereHas('dte', fn ($dte) => $dte
            ->whereNotIn('id', Dte::sinNcProntoPago()->select('id')));

        foreach (PpqLote::whereHas('items', $candidatos)->orderBy('id')->get() as $lote) {
            DB::transaction(function () use ($lote, $candidatos, $simular, &$quitadas, &$conservadas) {
                $actual = PpqLote::whereKey($lote->id)->lockForUpdate()->first();
                if ($actual === null) {
                    return;
                }
                $items = $actual->items()->where($candidatos)->orderBy('id')->lockForUpdate()->get();
                // Mismo resguardo que ReporteCasoCalleja::sacarDelLote(): lo conciliado o con
                // historia de conciliación no se quita.
                $conHistoria = DB::table('ppq_conciliacion_movimientos')->whereIn('ppq_item_id', $items->pluck('id'))->pluck('ppq_item_id')->flip();
                foreach ($items as $item) {
                    if ($item->estaConciliado() || $conHistoria->has($item->id)) {
                        $conservadas[] = [$actual->id, $item->numero_control, $item->estaConciliado()
                            ? 'Conciliada: '.$item->estadoPagoLabel() : 'Tiene historia de conciliación'];

                        continue;
                    }
                    $quitadas[] = [$actual->id, $item->numero_control, $item->monto_dte];
                    if ($simular) {
                        continue;
                    }
                    $item->delete();
                    $actual->update([
                        'observaciones' => trim(($actual->observaciones ?? '')."\nNC de pronto pago {$item->numero_control} quitada del PPQ el ".now()->format('d/m/Y').': no va en el quedan.'),
                    ]);
                    activity()->performedOn($actual)->withProperties([
                        'dte_id' => $item->dte_id,
                        'numero_control' => $item->numero_control,
                        'monto' => $item->monto_dte,
                    ])->log('ppq.nc_pronto_pago_quitada');
                }
            });
        }

        $this->info($simular ? 'NC que se quitarían:' : 'NC quitadas:');
        $this->table(['PPQ', 'NC', 'Monto'], $quitadas);
        $this->info('NC conservadas:');
        $this->table(['PPQ', 'NC', 'Motivo'], $conservadas);
        $this->info('Total: '.count($quitadas).' quitadas; '.count($conservadas).' conservadas.');
        if ($simular) {
            $this->warn('Simulación: no se cambió nada.');
        }

        return self::SUCCESS;
    }
}
