<?php

namespace App\Console\Commands;

use App\Models\PpqLote;
use App\Services\Ppq\EstadoRealLotePpq;
use Illuminate\Console\Command;

/**
 * Completa `ppq_lotes.cliente_id` en los lotes que lo tienen vacío (también los borrados),
 * con el cliente de sus CCF en el Seguimiento, solo cuando todos son del mismo cliente.
 * Idempotente: una segunda corrida no encuentra nada que completar.
 */
class PpqCompletarClienteLotesCommand extends Command
{
    protected $signature = 'ppq:completar-cliente-lotes {--dry-run : Solo muestra lo que haría, sin cambiar nada}';

    protected $description = 'Completa el cliente de lotes PPQ desde sus documentos en seguimiento';

    public function handle(EstadoRealLotePpq $servicio): int
    {
        $propuestos = 0;
        $omitidos = 0;
        $filas = [];
        PpqLote::withTrashed()->whereNull('cliente_id')->chunkById(200, function ($lotes) use ($servicio, &$propuestos, &$omitidos, &$filas) {
            $estados = $servicio->calcular($lotes);
            foreach ($lotes as $lote) {
                $real = $estados[$lote->id];
                $cliente = $real['cliente_derivado'];
                $filas[] = [$lote->id, $lote->referencia, $cliente?->nombre ?? $real['cliente_motivo']];
                if ($cliente) {
                    $propuestos++;
                    if (! $this->option('dry-run')) {
                        $lote->update(['cliente_id' => $cliente->id]);
                    }
                } else {
                    $omitidos++;
                }
            }
        });
        $this->table(['Lote', 'Referencia', 'Cliente propuesto o motivo'], $filas);
        $this->info('Propuestos: '.$propuestos.' · Actualizados: '.($this->option('dry-run') ? 0 : $propuestos).' · Omitidos: '.$omitidos);

        return self::SUCCESS;
    }
}
