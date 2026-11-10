<?php

namespace App\Console\Commands;

use App\Services\Rutas\PorEntregar;
use App\Services\Rutas\Viajes;
use Illuminate\Console\Command;

/**
 * Cierra cada mañana lo que nadie armó y ya terminó. Dos casos:
 *
 *  - Las salidas de «Por entregar» de días anteriores ({@see PorEntregar::finalizarAutomaticasViejas()}):
 *    se finalizan con el regreso el día en que se armaron; las vacías se cancelan.
 *  - Los viajes ({@see Viajes::cerrarTerminados()}): NO se cierran por fecha. El que pasó un
 *    día sin pedidos deja de sumar; el que ya no suma se cierra cuando todo está facturado y
 *    entregado, o tras {@see Viajes::DIAS_SIN_MOVIMIENTO} días sin movimiento.
 *
 * No liquida nada: la oficina hace «Terminar ruta».
 */
class RutasFinalizarAutomaticasCommand extends Command
{
    protected $signature = 'rutas:finalizar-automaticas';

    protected $description = 'Cierra las salidas de «Por entregar» de días anteriores y los viajes que ya terminaron (no las liquida)';

    public function handle(PorEntregar $porEntregar, Viajes $viajes): int
    {
        $cuenta = $porEntregar->finalizarAutomaticasViejas();
        $this->info("Salidas automáticas finalizadas: {$cuenta['finalizadas']}. Canceladas por vacías: {$cuenta['canceladas']}.");

        $viaje = $viajes->cerrarTerminados();
        $this->info("Viajes que dejaron de sumar pedidos: {$viaje['dejaronDeSumar']}. Cerrados: {$viaje['finalizados']}. Cancelados por vacíos: {$viaje['cancelados']}.");

        return self::SUCCESS;
    }
}
