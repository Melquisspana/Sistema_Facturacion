<?php

namespace App\Console\Commands;

use App\Services\Dte\DteFirmaService;
use Illuminate\Console\Command;

/**
 * Prueba CONTROLADA del endpoint POST del firmador local con un payload FAKE.
 * NO firma ningún DTE real: usa NIT de prueba, contraseña fake y un dteJson
 * inventado. Un error controlado del firmador (certificado/llave no encontrada,
 * datos requeridos, password incorrecto) es la respuesta ESPERADA y confirma
 * que el endpoint está vivo y procesa POST.
 *
 * No lee DTE, no toca BD, no usa certificados ni contraseñas reales.
 */
class DteFirmaPostTestCommand extends Command
{
    protected $signature = 'dte:firma-post-test';

    protected $description = 'Prueba controlada del POST del firmador con payload fake (no firma ningún DTE)';

    public function handle(DteFirmaService $firma): int
    {
        $this->line('Prueba de POST al firmador local (payload FAKE — sin DTE real).');
        $this->newLine();

        $r = $firma->postTest();

        $this->line('Payload enviado (sin secretos):');
        $this->line('  '.json_encode($r['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $this->line('URL consultada      : '.$r['url']);
        $this->line('HTTP status         : '.($r['http_status'] ?? 'sin respuesta'));
        $this->line('Estado del firmador : '.($r['firmador_status'] ?? '—'));
        if ($r['codigo'] !== null) {
            $this->line('Código              : '.$r['codigo']);
        }
        $this->line('Mensaje             : '.$r['mensaje']);
        $this->newLine();

        $resultado = self::SUCCESS;

        if (! $r['procesa']) {
            $this->warn('El endpoint NO respondió (verifique que el firmador esté levantado).');
            $resultado = self::FAILURE;
        } elseif ($r['firmo']) {
            // No esperado con datos fake: el firmador no debería firmar sin certificado válido.
            $this->warn('El firmador devolvió OK con datos FAKE (inesperado). Revisar configuración del firmador.');
        } else {
            $this->info('Endpoint VIVO: procesó el POST y devolvió un error controlado (esperado con datos fake).');
        }

        $this->newLine();
        $this->warn('*** PRUEBA FAKE — NO SE FIRMÓ NINGÚN DTE / NO SE TRANSMITIÓ / NO SE ENVIÓ A HACIENDA ***');

        return $resultado;
    }
}
