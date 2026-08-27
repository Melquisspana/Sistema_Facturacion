<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use App\Services\Dte\DteTransmisionResiliente;
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
    protected $signature = 'dte:transmitir {dte : ID del DTE}
        {--estado-incierto : Caso 2 del manual: el desenlace de un intento anterior no se conoce, así que consulta ANTES del primer envío}';

    protected $description = 'Transmite un DTE firmado a recepción (bloqueado si la transmisión está deshabilitada)';

    public function handle(DteTransmisionResiliente $resiliente): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        // La política de reintentos del Manual V2.0 NO es opcional: es la única forma
        // de transmitir. Este comando y la UI pasan por la misma, para que no existan
        // dos maneras distintas de enviar un DTE.
        try {
            $r = $resiliente->transmitir($dte, (bool) $this->option('estado-incierto'));
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

        $this->newLine();
        $this->line('Política de reintentos: '.$r['envios'].' envío(s), '.$r['consultas'].' consulta(s).');
        foreach ($r['traza'] as $paso) {
            $this->line('  · '.$paso);
        }
        $this->newLine();

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

        if (! empty($r['consulta_no_disponible'])) {
            $this->newLine();
            $this->warn('ESTADO DE RECEPCIÓN INCIERTO — NO SE REENVIÓ.');
            $this->line('No se pudo determinar en Hacienda si el documento ya fue recibido'
                .($r['consulta_resultado'] ? ' (consulta: '.$r['consulta_resultado'].')' : '').', y sin');
            $this->line('esa certeza reenviar podría duplicarlo. NO se cambió el documento, NO se');
            $this->line('regeneró numeración y NO se activó contingencia. Reintentá cuando la consulta responda.');
        }

        if (! empty($r['contingencia_requerida'])) {
            $this->newLine();
            $this->error('REINTENTOS AGOTADOS — CONTINGENCIA REQUERIDA.');
            $this->line('No se activó ningún evento de contingencia: esa fase está pendiente y es una');
            $this->line('decisión con requisitos propios. El documento sigue SIN transmitir.');
        }

        return $r['resultado'] === 'aceptado' ? self::SUCCESS : self::FAILURE;
    }
}
