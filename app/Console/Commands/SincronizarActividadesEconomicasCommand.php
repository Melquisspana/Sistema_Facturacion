<?php

namespace App\Console\Commands;

use App\Services\Importacion\SincronizadorActividadesEconomicas;
use Illuminate\Console\Command;

/**
 * Sincroniza actividades_economicas (CAT-019) desde catalogos_mh (ya cargado con
 * el catálogo oficial completo del MH). Dry-run por defecto; --apply para
 * escribir. NUNCA borra actividades existentes ni cambia sus IDs.
 */
class SincronizarActividadesEconomicasCommand extends Command
{
    protected $signature = 'dte:sincronizar-actividades {--apply : Escribe los cambios en la base de datos (por defecto es dry-run)}';

    protected $description = 'Sincroniza actividades_economicas (CAT-019) desde catalogos_mh, sin borrar ni cambiar IDs existentes';

    public function handle(SincronizadorActividadesEconomicas $sincronizador): int
    {
        $aplicar = (bool) $this->option('apply');

        $r = $sincronizador->sincronizar($aplicar);

        if ($r['duplicados'] > 0 || $r['invalidos'] > 0) {
            $this->error("Fuente con problemas: {$r['duplicados']} código(s) duplicado(s) y {$r['invalidos']} fila(s) inválida(s) en catalogos_mh (cat=019). No se aplicó ningún cambio.");

            return self::FAILURE;
        }

        $this->table(
            ['Total fuente', 'Nuevos', 'Actualizados', 'Sin cambios', 'Inválidos', 'Duplicados'],
            [[$r['total_fuente'], $r['nuevos'], $r['actualizados'], $r['sin_cambios'], $r['invalidos'], $r['duplicados']]]
        );

        if ($aplicar) {
            $this->info('Aplicado: actividades_economicas sincronizado (sin borrar ni cambiar IDs existentes).');
        } else {
            $this->comment('DRY-RUN — no se modificó la base de datos.');
        }

        return self::SUCCESS;
    }
}
