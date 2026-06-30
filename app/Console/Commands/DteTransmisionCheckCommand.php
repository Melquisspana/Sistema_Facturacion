<?php

namespace App\Console\Commands;

use App\Models\Dte;
use App\Services\Dte\DteTransmisionService;
use Illuminate\Console\Command;

/**
 * Diagnóstico de SOLO LECTURA: ¿está un DTE listo para transmitir? Revisa
 * JSON/JWS/sello/estado y si la transmisión está habilitada o bloqueada.
 * NO transmite, NO toca BD, NO contacta a Hacienda.
 */
class DteTransmisionCheckCommand extends Command
{
    protected $signature = 'dte:transmision-check {dte : ID del DTE}';

    protected $description = 'Revisa si un DTE está listo para transmitir (no transmite nada)';

    public function handle(DteTransmisionService $transmision): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        $this->line('Revisión de transmisión — DTE #'.$dte->id.' ('.$dte->tipo_dte->label().')');
        $this->newLine();

        $r = $transmision->diagnosticar($dte);

        foreach ($r['checks'] as $check) {
            $icono = $check['ok'] ? '<info>OK </info>' : '<error>X  </error>';
            $this->line('  '.$icono.' '.$check['etiqueta'].' — '.$check['detalle']);
        }

        $this->newLine();
        $this->line('Transmisión: '.($r['habilitada']
            ? '<comment>HABILITADA por configuración</comment>'
            : '<info>BLOQUEADA (dte.transmision.enabled=false)</info>'));

        $this->newLine();
        if ($r['listo']) {
            $this->info('Precondiciones OK: el documento estaría listo para transmitir (cuando se habilite).');
        } else {
            $this->warn('El documento NO está listo para transmitir:');
            foreach ($r['problemas'] as $p) {
                $this->line('  - '.$p);
            }
        }

        $this->newLine();
        $this->warn('*** NO TRANSMITE / SOLO DIAGNÓSTICO — NO SE ENVIÓ NADA A HACIENDA ***');

        return $r['listo'] ? self::SUCCESS : self::FAILURE;
    }
}
