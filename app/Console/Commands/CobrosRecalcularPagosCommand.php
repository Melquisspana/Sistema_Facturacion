<?php

namespace App\Console\Commands;

use App\Models\Cobros\CobroDocumento;
use Illuminate\Console\Command;

/**
 * Vuelve a calcular el estado de pago de los CCF con cobro registrado, con la regla vigente
 * de {@see CobroDocumento::facturadoEfectivo()}. Sirve después de corregir esa regla: los
 * CCF pagados por su TOTAL con la NC descontada en una línea aparte habían quedado «con
 * diferencia». Solo recalcula desde los eventos (no crea ni borra cobros). Idempotente.
 */
class CobrosRecalcularPagosCommand extends Command
{
    protected $signature = 'cobros:recalcular-pagos
        {--cliente= : Solo los CCF de este cliente (id)}
        {--dry-run : Solo muestra lo que cambiaría, sin cambiar nada}';

    protected $description = 'Recalcula el estado de pago de los CCF con cobro registrado según la regla vigente';

    public function handle(): int
    {
        $cliente = $this->option('cliente');
        if (filled($cliente) && ! ctype_digit((string) $cliente)) {
            $this->error('Indique un id de cliente válido.');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('dry-run');
        $cambios = [];
        $revisados = 0;

        CobroDocumento::query()
            ->where('tipo_dte', '03')
            ->where('monto_pagado', '!=', 0)
            ->when(filled($cliente), fn ($q) => $q->where('cliente_id', (int) $cliente))
            ->orderBy('id')
            ->chunkById(200, function ($documentos) use ($simular, &$cambios, &$revisados) {
                foreach ($documentos as $documento) {
                    $revisados++;
                    $antes = $documento->pago_estado;
                    $despues = CobroDocumento::estadoDePago($documento->facturadoEfectivo(), $documento->monto_pagado);
                    if ($antes === $despues) {
                        continue;
                    }

                    $cambios[] = [$documento->correlativoCorto(), $documento->monto, $documento->facturadoEfectivo(),
                        $documento->monto_pagado, $antes?->label() ?? '—', $despues->label()];
                    if (! $simular) {
                        $documento->recalcularPago();
                    }
                }
            });

        if ($cambios !== []) {
            $this->table(['CCF', 'Monto', 'A cobrar (con NC)', 'Cobrado', 'Antes', 'Ahora'], $cambios);
        }
        $this->info(($simular ? 'Cambiarían' : 'Recalculados').': '.count($cambios).' de '.$revisados.' CCF con cobro.');
        if ($simular) {
            $this->warn('Simulación: no se cambió nada.');
        }

        return self::SUCCESS;
    }
}
