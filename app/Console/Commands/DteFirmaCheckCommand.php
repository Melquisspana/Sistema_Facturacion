<?php

namespace App\Console\Commands;

use App\Models\Dte;
use App\Services\Dte\DteFirmaService;
use Illuminate\Console\Command;

/**
 * Diagnóstico de SOLO LECTURA: ¿está un DTE listo para la futura firma?
 * Revisa estado/numeración/JSON/configuración. NO firma, NO transmite, NO toca BD.
 */
class DteFirmaCheckCommand extends Command
{
    protected $signature = 'dte:firma-check {dte : ID del DTE}';

    protected $description = 'Revisa si un DTE está listo para firmar (no firma ni transmite nada)';

    public function handle(DteFirmaService $firma): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        $this->line('Revisión de firma — DTE #'.$dte->id.' ('.$dte->tipo_dte->label().')');
        $this->newLine();

        $r = $firma->diagnosticar($dte);

        foreach ($r['checks'] as $check) {
            $icono = $check['ok'] ? '<info>OK </info>' : '<error>X  </error>';
            $this->line('  '.$icono.' '.$check['etiqueta'].' — '.$check['detalle']);
        }

        $this->newLine();
        if ($r['listo']) {
            $this->info('El documento está LISTO para firmar (cuando se habilite la firma).');
        } else {
            $this->warn('El documento NO está listo para firmar:');
            foreach ($r['problemas'] as $p) {
                $this->line('  - '.$p);
            }
        }

        // Disponibilidad del firmador local (health check, NO firma).
        $this->newLine();
        $this->line('Firmador local (health check):');
        $h = $firma->healthCheck();
        $icono = $h['disponible'] ? '<info>OK </info>' : '<error>X  </error>';
        $this->line('  '.$icono.' URL consultada — '.$h['url']);
        $this->line('       status code  — '.($h['status'] ?? 'sin respuesta'));
        $this->line('       mensaje      — '.$h['mensaje']);
        if ($h['disponible']) {
            $this->info('  El firmador local responde.');
        } else {
            $this->warn('  El firmador local NO responde (verifique que esté levantado).');
        }

        $this->newLine();
        $this->warn('*** DIAGNÓSTICO — NO SE FIRMÓ / NO SE TRANSMITIÓ / NO SE ENVIÓ A HACIENDA ***');

        return $r['listo'] ? self::SUCCESS : self::FAILURE;
    }
}
