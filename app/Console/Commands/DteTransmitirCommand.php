<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use App\Services\Dte\DteTransmisionService;
use Illuminate\Console\Command;

/**
 * Transmite un DTE firmado a recepción. Bloqueado por los candados de seguridad
 * (producción) salvo que esté abierta la vía dedicada de pruebas
 * (DTE_TRANSMISION_TEST_ENABLED=true con ambiente=testing → apitest).
 *
 * Ante una respuesta DEFINITIVA del MH (aceptado/rechazado) persiste el sello, la
 * respuesta completa (respuesta_mh) y avanza el estado por la máquina. No imprime tokens.
 */
class DteTransmitirCommand extends Command
{
    protected $signature = 'dte:transmitir {dte : ID del DTE}';

    protected $description = 'Transmite un DTE firmado a recepción (bloqueado si la transmisión está deshabilitada)';

    public function handle(DteTransmisionService $transmision): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        try {
            $r = $transmision->transmitir($dte);
        } catch (DteTransmisionDeshabilitadaException $e) {
            // Razón(es) específica(s) del/los candado(s) que bloquean la transmisión real.
            $this->warn('Transmisión real bloqueada por candados de seguridad:');
            foreach (explode(' | ', $e->getMessage()) as $razon) {
                $this->line('  - '.$razon);
            }

            return self::FAILURE;
        } catch (DteTransmisionException $e) {
            $this->error('No se puede transmitir: '.$e->getMessage());

            return self::FAILURE;
        }

        $dte->refresh();

        $this->line('Resultado de transmisión: '.$r['resultado']);
        $this->line('HTTP status            : '.($r['http_status'] ?? 'sin respuesta'));
        $this->line('Mensaje                : '.$r['mensaje']);
        if (! empty($r['observaciones'])) {
            $this->newLine();
            $this->line('Observaciones del MH:');
            foreach ($r['observaciones'] as $obs) {
                $this->line('  - '.$obs);
            }
        }

        if (in_array($r['resultado'], ['aceptado', 'rechazado'], true)) {
            $this->newLine();
            $this->line('Estado del documento   : '.$dte->estado->label());
            if (filled($dte->sello_recepcion)) {
                $this->line('Sello de recepción     : '.$dte->sello_recepcion);
            }
            $this->line('Respuesta guardada en  : '.($dte->respuesta_mh_path ?: '(no guardada)'));
        } else {
            $this->newLine();
            $this->warn('Resultado transitorio ('.$r['resultado'].'): el documento sigue en '.$dte->estado->label().' y se puede reintentar.');
        }

        return $r['resultado'] === 'aceptado' ? self::SUCCESS : self::FAILURE;
    }
}
