<?php

namespace App\Console\Commands;

use App\Models\Dte;
use App\Services\Dte\DteTransmisionService;
use Illuminate\Console\Command;

/**
 * Checklist de riesgo previo a una transmisión REAL. SOLO LECTURA: no hace HTTP,
 * no transmite, no muestra secretos. Resultado final: BLOQUEADO o LISTO.
 */
class DtePreflightRealCommand extends Command
{
    protected $signature = 'dte:preflight-real {dte : ID del DTE}';

    protected $description = 'Checklist de riesgo previo a transmisión real (no transmite)';

    public function handle(DteTransmisionService $transmision): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        $this->line('Preflight de transmisión real — DTE #'.$dte->id.' ('.$dte->tipo_dte->label().')');
        $this->newLine();

        $r = $transmision->preflight($dte);
        foreach ($r['checks'] as $check) {
            $icono = $check['ok'] ? '<info>OK </info>' : '<error>!! </error>';
            $this->line('  '.$icono.str_pad($check['etiqueta'], 34).' : '.$check['detalle']);
        }

        $this->newLine();
        if ($r['listo']) {
            $this->info('Resultado: LISTO (todos los candados abiertos). Aun así, este comando NO transmite.');
        } else {
            $this->warn('Resultado: BLOQUEADO. Falta abrir candados o completar precondiciones.');
        }

        $this->newLine();
        $this->warn('*** PREFLIGHT — SOLO DIAGNÓSTICO / NO SE TRANSMITIÓ NADA ***');

        return $r['listo'] ? self::SUCCESS : self::FAILURE;
    }
}
