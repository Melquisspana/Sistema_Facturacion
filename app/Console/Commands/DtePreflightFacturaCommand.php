<?php

namespace App\Console\Commands;

use App\Models\Dte;
use App\Services\Dte\PreflightEmisionProduccionFactura;
use Illuminate\Console\Command;

/**
 * Checklist de READINESS para Factura consumidor final (tipo 01) en producción.
 * SOLO DIAGNÓSTICO: no firma, no transmite, no cambia estado, no toca
 * correlativos. NO conectado a ningún botón ni a la emisión real — los guards
 * que bloquean Factura en producción siguen intactos sin importar el resultado.
 */
class DtePreflightFacturaCommand extends Command
{
    protected $signature = 'dte:preflight-factura {dte : ID del DTE}';

    protected $description = 'Checklist de readiness para producción de Factura consumidor final (no emite nada)';

    public function handle(PreflightEmisionProduccionFactura $preflight): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        $dte->loadMissing(['cliente', 'lineas']);

        $this->line('Preflight de readiness — Factura consumidor final #'.$dte->id);
        $this->newLine();

        $r = $preflight->evaluar($dte);
        foreach ($r['checks'] as $check) {
            $icono = $check['ok'] ? '<info>OK </info>' : '<error>!! </error>';
            $this->line('  '.$icono.str_pad($check['label'], 55).' : '.$check['detalle']);
        }

        $this->newLine();
        if ($r['puede']) {
            $this->info('Resultado: LISTO (checklist completo).');
        } else {
            $this->warn('Resultado: BLOQUEADO. Faltan: '.implode(' | ', $r['faltantes']));
        }

        $this->newLine();
        $this->warn('*** SOLO DIAGNÓSTICO — no se emitió, firmó ni transmitió nada. Este resultado NO habilita producción por sí mismo. ***');

        return $r['puede'] ? self::SUCCESS : self::FAILURE;
    }
}
