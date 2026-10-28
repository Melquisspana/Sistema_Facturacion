<?php

namespace App\Console\Commands;

use App\Models\Municipio;
use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Puebla `distritos.municipio_codigo` con el código CAT-013 del municipio 2024 al que
 * pertenece cada distrito, para que la coherencia municipio ↔ distrito se pueda validar
 * por código y no por el nombre suelto de la agrupación.
 *
 * Idempotente. Determinista: empareja por nombre normalizado contra el CAT-013 oficial
 * importado en `catalogos_mh`; si algo queda ambiguo o sin correspondencia, FALLA con el
 * detalle en lugar de dejar filas en NULL o asignar un código a ciegas.
 *
 * La migración `add_municipio_codigo_a_distritos` ya hace esto al aplicarse. Este comando
 * sirve para re-vincular tras re-sembrar distritos o importar de nuevo los catálogos.
 */
class VincularMunicipioDistrito extends Command
{
    protected $signature = 'distritos:vincular-municipio {--dry-run : Solo informa cuántos se vincularían, sin escribir}';

    protected $description = 'Vincula cada distrito (CAT-008) con su municipio fiscal 2024 (CAT-013) en distritos.municipio_codigo.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! VinculaMunicipioDistrito::hayCatalogo()) {
            $this->error('No hay CAT-013 en catalogos_mh. Corré primero `php artisan dte:catalogos` y reintentá.');

            return self::FAILURE;
        }

        try {
            $r = VinculaMunicipioDistrito::ejecutar($dry);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // El vínculo por sí solo no basta para poder ELEGIR el municipio: además tiene
        // que existir una fila en `municipios` que represente a la agrupación.
        $agrupaciones = VinculaMunicipioDistrito::asegurarAgrupaciones($dry);

        Municipio::olvidarNombresFiscales();

        $this->table(
            ['Municipios 2024 mapeados', 'Distritos vinculados', 'Ya correctos', 'Total distritos'],
            [[count($r['mapa']), $r['vinculados'], $r['sin_cambios'], $r['total']]]
        );

        if ($agrupaciones['agrupaciones'] !== []) {
            $this->newLine();
            $this->info(($dry ? '[dry-run] ' : '').'Agrupaciones 2024 que no se podían elegir y ahora sí:');
            foreach ($agrupaciones['agrupaciones'] as $nombre) {
                $this->line('  + '.$nombre);
            }
        }

        foreach ($agrupaciones['conflictos'] as $conflicto) {
            $this->warn('  ! '.$conflicto.' — se dejó como estaba, revisalo a mano.');
        }

        $this->info(($dry ? '[dry-run] ' : '')."Vínculo distrito → municipio CAT-013 al día ({$r['total']} distritos).");

        return self::SUCCESS;
    }
}
