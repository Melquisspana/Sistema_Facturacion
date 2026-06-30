<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use App\Services\Dte\DteTransmisionService;
use Illuminate\Console\Command;

/**
 * DRY-RUN formal de transmisión: valida precondiciones y arma el payload final,
 * pero NO hace HTTP, NO guarda sello, NO cambia estado, NO transmite. Muestra un
 * resumen seguro (sin token, sin contraseña, sin el JWS completo).
 */
class DteTransmitirDryRunCommand extends Command
{
    protected $signature = 'dte:transmitir-dry-run {dte : ID del DTE}';

    protected $description = 'Simula la transmisión de un DTE (dry-run): arma el payload pero no hace HTTP';

    public function handle(DteTransmisionService $transmision): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        try {
            $r = $transmision->dryRun($dte);
        } catch (DteTransmisionException $e) {
            $this->error('No se puede preparar la transmisión: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('DRY-RUN de transmisión — DTE #'.$dte->id);
        $this->newLine();
        $this->line('  tipoDte           : '.$r['tipoDte']);
        $this->line('  ambiente (MH)     : '.$r['ambiente']);
        $this->line('  ambiente (transm.): '.$r['ambiente_transmision']);
        $this->line('  version           : '.$r['version']);
        $this->line('  codigoGeneracion  : '.$r['codigoGeneracion']);
        $this->line('  JWS firmado       : '.($r['tiene_jws'] ? 'sí' : 'no').' ['.$r['jws_preview'].']');
        $this->line('  endpoint recepción: '.$r['endpoint']);
        $this->line('  auth configurado  : '.($r['auth_configurado'] ? 'sí' : 'no'));

        $this->newLine();
        $c = $r['candados'];
        $this->line('Candados: '.($c['bloqueado'] ? '<error>BLOQUEAN la transmisión real</error>' : '<info>ninguno bloquea</info>'));
        foreach ($c['razones'] as $razon) {
            $this->line('  - '.$razon);
        }

        $this->newLine();
        $this->warn('*** DRY-RUN — NO SE HIZO HTTP / NO SE GUARDÓ SELLO / NO SE CAMBIÓ ESTADO / NO SE TRANSMITIÓ ***');

        return self::SUCCESS;
    }
}
