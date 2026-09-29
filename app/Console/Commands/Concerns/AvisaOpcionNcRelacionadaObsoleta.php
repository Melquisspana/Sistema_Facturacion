<?php

namespace App\Console\Commands\Concerns;

/**
 * Compatibilidad hacia atrás de `--confirmo-nc-relacionada` en los comandos de
 * invalidación.
 *
 * La opción existía cuando la nota de crédito relacionada se trataba como un RIESGO
 * ASUMIBLE («posible doble corrección fiscal, confirmá bajo tu responsabilidad»). No lo
 * es: el Manual Funcional v2.0 (págs. 13-16) PROHÍBE invalidar un comprobante mientras
 * tenga una nota de crédito o de débito validada vigente. Primero se invalida la nota.
 *
 * Por eso la bandera se conserva —un script antiguo que la pase no revienta— pero ya no
 * hace absolutamente nada, y se dice en voz alta. Quitarla del todo habría convertido
 * cada automatismo existente en un error de sintaxis; dejarla funcionando habría dejado
 * abierta justo la puerta que este cambio cierra.
 */
trait AvisaOpcionNcRelacionadaObsoleta
{
    /** Avisa (una vez por corrida) si alguien todavía pasa la opción obsoleta. */
    protected function avisarOpcionObsoleta(): void
    {
        if (! (bool) $this->option('confirmo-nc-relacionada')) {
            return;
        }

        $this->warn('--confirmo-nc-relacionada está OBSOLETA y se ignora. Un comprobante con una nota de '
            .'crédito/débito vigente no se puede invalidar por ninguna vía: invalidá primero esa nota y '
            .'después volvé a intentar.');
    }
}
