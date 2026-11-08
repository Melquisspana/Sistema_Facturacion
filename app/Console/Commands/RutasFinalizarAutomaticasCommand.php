<?php

namespace App\Console\Commands;

use App\Services\Rutas\PorEntregar;
use Illuminate\Console\Command;

/**
 * Finaliza las salidas que se armaron solas en «Por entregar» los días anteriores
 * ({@see PorEntregar::finalizarAutomaticasViejas()}). Sin esto quedaban en curso para siempre:
 * la ruta figuraba «en ruta» en el tablero y «Mi salida» abría la vieja. No las liquida.
 */
class RutasFinalizarAutomaticasCommand extends Command
{
    protected $signature = 'rutas:finalizar-automaticas';

    protected $description = 'Finaliza las salidas automáticas de «Por entregar» de días anteriores (no las liquida)';

    public function handle(PorEntregar $porEntregar): int
    {
        $this->info('Salidas automáticas finalizadas: '.$porEntregar->finalizarAutomaticasViejas().'.');

        return self::SUCCESS;
    }
}
