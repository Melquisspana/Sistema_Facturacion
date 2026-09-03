<?php

namespace App\Console\Commands;

use App\Services\Importacion\ImportadorCatalogosMh;
use App\Support\Dte\CatalogoOficialMh;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Importa los catálogos oficiales del MH desde el Excel oficial a catalogos_mh.
 * Idempotente. No genera JSON, no firma, no transmite.
 */
class ImportarCatalogosMhCommand extends Command
{
    protected $signature = 'dte:catalogos {--archivo= : Ruta del .xlsx (por defecto, el de resources/dte/catalogos)}';

    protected $description = 'Importa los catálogos oficiales del MH (CAT-001..033) desde el Excel oficial';

    public function handle(ImportadorCatalogosMh $importador): int
    {
        $archivo = $this->option('archivo') ?: null;

        try {
            // Sin --archivo: el catálogo ACTIVO del registro, verificado por SHA-256 antes
            // de tocar la base. Con --archivo: ese archivo tal cual, para inspeccionar una
            // revisión todavía no registrada.
            if ($archivo === null) {
                $this->info('Catálogo activo: '.CatalogoOficialMh::version());
                $this->line('  archivo: '.basename(CatalogoOficialMh::ruta()));
                $this->line('  sha-256: '.CatalogoOficialMh::sha256Esperado().' (verificado)');
            } else {
                $this->warn('Importando un archivo AD-HOC (sin verificación de hash): '.basename($archivo));
            }

            $r = $importador->importar($archivo);
        } catch (RuntimeException $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }

        $filas = [];
        ksort($r['cats']);
        foreach ($r['cats'] as $cat => $n) {
            $filas[] = ['CAT-'.$cat, $r['nombres'][$cat] ?? '', $n];
        }
        $this->table(['Catálogo', 'Nombre', 'Registros'], $filas);

        $this->info("Listo: {$r['secciones']} secciones, {$r['total']} registros importados desde {$r['archivo']}.");

        return self::SUCCESS;
    }
}
