<?php

namespace App\Console\Commands;

use App\Enums\TipoDte;
use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteFirmaException;
use App\Models\Dte;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteTransmisionService;
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

    public function handle(DteFirmaService $firma, DteTransmisionService $transmision): int
    {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        // GUARDIA: Factura consumidor final (01) sigue "en revisión" (nunca se probó
        // firma/transmisión real con Hacienda para este tipo). Bloquea esta vía de
        // consola cuando una emisión real a producción sería posible ahora mismo; en
        // modo seguro (paralelo/mock/dry-run/apitest) no aplica. Mismo criterio que el
        // gate web de DteController::firmarTransmitir(). Solo lectura de candados: NO
        // llama a transmisión ni firmador.
        if ($dte->tipo_dte === TipoDte::Factura && $transmision->emisionRealPosible()) {
            $this->error('Factura consumidor final está en revisión y no puede firmarse en producción todavía.');

            return self::FAILURE;
        }

        // GUARDIA: Factura de exportación (11) sigue "en revisión" (incoterms, régimen
        // y recinto fiscal aún no se capturan/serializan). Mismo criterio que la guardia
        // de Factura consumidor final. Solo lectura de candados: NO llama al firmador.
        if ($dte->tipo_dte === TipoDte::FacturaExportacion && $transmision->emisionRealPosible()) {
            $this->error('Factura de exportación está en revisión y no puede firmarse en producción todavía.');

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
