<?php

namespace Tests\Support;

use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Services\Rutas\EntregasCcf;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Para armar el escenario de una salida de antes del 10/11/2026: la que la oficina armaba a
 * mano y llevaba los CCF pendientes de su ruta. Ya nadie carga los pendientes de una ruta en
 * el sistema (el viaje lleva lo que se marca); las pruebas de las reglas de entrega siguen
 * necesitando una salida con CCF adentro, y la llenan con esto.
 */
trait CcfDeLaRuta
{
    /** Los CCF pendientes de las salas activas de la ruta que todavía no van en una salida abierta. */
    protected function pendientesDeLaRuta(Ruta $ruta): Collection
    {
        return app(EntregasCcf::class)->pendientesDeSalas($ruta->sucursales()->where('activo', true)->pluck('id')->all());
    }

    /**
     * Mete en la salida los CCF pendientes de su ruta, uno por uno y con los controles de
     * siempre ({@see EntregasCcf::meterCcf()}). Devuelve cuántos entraron; el que choca (ya va
     * en otra salida abierta, ya se entregó) se salta.
     */
    protected function cargarCcfDeLaRuta(SalidaRuta $salida): int
    {
        if (! $salida->proposito->entrega()) {
            return 0;
        }

        $cargados = 0;
        foreach ($this->pendientesDeLaRuta($salida->ruta()->firstOrFail()) as $dte) {
            try {
                app(EntregasCcf::class)->meterCcf($salida, $dte->id);
                $cargados++;
            } catch (ValidationException $e) {
                if (! array_key_exists('numero_control', $e->errors())) {
                    throw $e;
                }
            }
        }

        return $cargados;
    }
}
