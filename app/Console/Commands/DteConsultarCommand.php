<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use App\Services\Dte\DteConsultaService;
use Illuminate\Console\Command;

/**
 * Consulta en Hacienda el estado de un DTE ya transmitido.
 *
 * SOLO LEE: no transmite, no cambia el estado del documento, no persiste nada. Por
 * defecto ni siquiera hace HTTP — muestra a qué URL iría y con qué cuerpo. Para
 * preguntar de verdad hace falta `--consultar`, y aun así siguen mandando los candados
 * de la integración (`dte.transmision.enabled` o la vía dedicada de pruebas).
 *
 * Nunca imprime el token ni credenciales.
 */
class DteConsultarCommand extends Command
{
    protected $signature = 'dte:consultar {dte : ID del DTE}
        {--consultar : Hace la consulta REAL al MH (por defecto solo muestra qué se enviaría)}';

    protected $description = 'Consulta el estado de un DTE en Hacienda (solo lectura; por defecto sin HTTP)';

    public function handle(DteConsultaService $consulta): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        try {
            $plan = $consulta->dryRun($dte);
        } catch (DteTransmisionException $e) {
            $this->error('No se puede consultar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Método      : '.$plan['metodo']);
        $this->line('URL         : '.$plan['url']);
        $this->line('Integración : '.($plan['habilitada'] ? 'habilitada' : 'DESHABILITADA (no se consultará)'));
        $this->newLine();
        $this->line('Cuerpo de la consulta:');
        foreach ($plan['body'] as $campo => $valor) {
            $this->line('  '.str_pad($campo, 18).': '.$valor);
        }

        if (! $this->option('consultar')) {
            $this->newLine();
            $this->warn('*** SOLO VISTA PREVIA — no se hizo ninguna petición. Usá --consultar para preguntar de verdad. ***');

            return self::SUCCESS;
        }

        $this->newLine();
        try {
            $r = $consulta->consultar($dte);
        } catch (DteTransmisionDeshabilitadaException $e) {
            $this->warn('Consulta bloqueada: '.$e->getMessage());

            return self::FAILURE;
        } catch (DteTransmisionException $e) {
            $this->error('No se pudo consultar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Resultado   : '.$r['resultado']);
        $this->line('HTTP status : '.($r['http_status'] ?? 'sin respuesta'));
        $this->line('¿Recibido?  : '.($r['recibido'] ? 'SÍ — Hacienda lo tiene' : 'no confirmado'));
        $this->line('Estado MH   : '.($r['estado_mh'] ?? '(ninguno)'));
        $this->line('Mensaje     : '.$r['mensaje']);
        if (filled($r['sello'])) {
            $this->line('Sello       : '.$r['sello']);
        }

        $this->newLine();
        $this->warn('*** SOLO LECTURA — el estado del documento no se modificó. ***');

        return $r['recibido'] ? self::SUCCESS : self::FAILURE;
    }
}
