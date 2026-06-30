<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteJsonInvalidoException;
use App\Exceptions\Dte\DteJsonException;
use App\Exceptions\Dte\DteNoMapeableException;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Services\Dte\DteJsonService;
use Illuminate\Console\Command;

/**
 * Genera el JSON oficial PRELIMINAR de un CCF (asigna numeración, serializa,
 * valida contra el schema y guarda el archivo + json_generado_path).
 *
 * NO firma, NO transmite, NO guarda sello, NO cambia estado, NO contacta Hacienda.
 */
class DteGenerarJsonCommand extends Command
{
    protected $signature = 'dte:generar-json {dte : ID del CCF} {--force : Regenera aunque ya exista json_generado_path}';

    protected $description = 'Genera el JSON oficial preliminar de un CCF (sin firma ni transmisión)';

    public function handle(DteJsonService $servicio): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        try {
            $r = $servicio->generar($dte, (bool) $this->option('force'));
        } catch (DteJsonInvalidoException $e) {
            $this->error('El JSON NO pasó la validación contra el schema (no se guardó nada):');
            foreach (array_slice($e->errores, 0, 40) as $err) {
                $this->line('  - '.$err);
            }

            return self::FAILURE;
        } catch (DteNoMapeableException $e) {
            $this->error('No se puede mapear el DTE:');
            foreach ($e->problemas as $p) {
                $this->line('  - '.$p);
            }

            return self::FAILURE;
        } catch (DteNoSerializableException $e) {
            $this->error('No se puede serializar a JSON oficial:');
            foreach ($e->problemas as $p) {
                $this->line('  - '.$p);
            }

            return self::FAILURE;
        } catch (DteJsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('JSON oficial preliminar generado y validado contra el schema.');
        $this->newLine();
        $this->line('  numeroControl    : '.$r['numeroControl']);
        $this->line('  codigoGeneracion : '.$r['codigoGeneracion']);
        $this->line('  archivo          : storage/app/'.$r['ruta']);
        $this->newLine();
        $this->warn('*** SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA ***');

        return self::SUCCESS;
    }
}
