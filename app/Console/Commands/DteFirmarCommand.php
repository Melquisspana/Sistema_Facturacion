<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteFirmaException;
use App\Models\Dte;
use App\Services\Dte\DteFirmaService;
use Illuminate\Console\Command;

/**
 * Firma LOCAL de un DTE que ya tiene JSON generado, usando el firmador local del
 * MH. Guarda el JWS firmado y json_firmado_path.
 *
 * Firmar localmente NO es emitir: NO transmite a Hacienda, NO guarda sello, NO
 * cambia el estado a aceptado. Nunca imprime la contraseña del certificado.
 */
class DteFirmarCommand extends Command
{
    protected $signature = 'dte:firmar {dte : ID del DTE} {--force : Re-firma aunque ya exista json_firmado_path}';

    protected $description = 'Firma localmente un DTE con JSON generado (sin transmisión a Hacienda)';

    public function handle(DteFirmaService $firma): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        try {
            $r = $firma->firmar($dte, (bool) $this->option('force'));
        } catch (DteFirmaDeshabilitadaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (DteFirmaException $e) {
            // El mensaje del servicio nunca incluye la contraseña.
            $this->error('No se pudo firmar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('DTE firmado localmente.');
        $this->newLine();
        $this->line('  numeroControl    : '.($r['numeroControl'] ?? '—'));
        $this->line('  codigoGeneracion : '.($r['codigoGeneracion'] ?? '—'));
        $this->line('  archivo firmado  : storage/app/'.$r['ruta']);
        $this->newLine();
        $this->warn('*** FIRMADO LOCALMENTE / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA ***');

        return self::SUCCESS;
    }
}
