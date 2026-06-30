<?php

namespace App\Console\Commands;

use App\Services\Importacion\ImportadorCatalogosMh;
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
        $archivo = $this->option('archivo') ?: $importador->archivoPorDefecto();
        if ($archivo === null) {
            $this->error('No se encontró ningún .xlsx en resources/dte/catalogos. Colocá el Excel oficial ahí.');

            return self::FAILURE;
        }

        $this->info('Importando catálogos desde: '.basename($archivo));

        try {
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

        $this->info("Listo: {$r['secciones']} secciones, {$r['total']} registros importados.");

        return self::SUCCESS;
    }
}
