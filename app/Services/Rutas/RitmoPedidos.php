<?php

namespace App\Services\Rutas;

use Carbon\CarbonImmutable;

/**
 * El ritmo de cada sala y de cada ruta, con las fechas de pedido que da la
 * {@see FuentePedidos} (hoy, los CCF). Regla completa en la sección 2 de
 * docs/PLAN_TABLERO_RUTAS.md:
 *
 *  · Sala: pedidos de los últimos 12 meses. Dos fechas del mismo día o con 1 día de
 *    diferencia son un solo pedido. El ritmo es la mediana de los últimos 10 intervalos.
 *  · Ruta: se juntan los pedidos de todas sus salas. Los días con pedidos sin un hueco de
 *    más de 3 días entre uno y otro son UNA vuelta (una salida dura varios días y cada
 *    sala pide un día distinto). El ritmo es la mediana entre el comienzo de una vuelta y
 *    el de la siguiente.
 */
class RitmoPedidos
{
    private const MESES = 12;

    /** Hasta 1 día después del comienzo, sigue siendo el mismo pedido. */
    private const MISMO_PEDIDO_DIAS = 1;

    /** Más de 3 días sin pedidos en la ruta: empieza otra vuelta. */
    private const HUECO_VUELTA_DIAS = 3;

    public function __construct(private readonly FuentePedidos $fuente) {}

    /**
     * Una consulta a la fuente para todas las salas.
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, Ritmo> sala_id => ritmo; también las que no tienen pedidos
     */
    public function porSala(array $salaIds, ?CarbonImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->startOfDay();
        $fechas = $this->fuente->fechasPorSala($salaIds, $hoy->subMonthsNoOverflow(self::MESES), $hoy);

        $ritmos = [];
        foreach ($salaIds as $id) {
            $ritmos[$id] = $this->deSala($fechas[$id] ?? []);
        }

        return $ritmos;
    }

    /** @param  array<int, CarbonImmutable>  $fechas  días con pedido, en orden */
    public function deSala(array $fechas): Ritmo
    {
        $pedidos = [];
        foreach ($fechas as $fecha) {
            $comienzo = end($pedidos);
            if ($comienzo === false || $comienzo->diffInDays($fecha) > self::MISMO_PEDIDO_DIAS) {
                $pedidos[] = $fecha;
            }
        }

        return new Ritmo($pedidos);
    }

    /** @param  iterable<Ritmo>  $salas  el ritmo de cada sala de la ruta */
    public function deRuta(iterable $salas): Ritmo
    {
        $dias = [];
        foreach ($salas as $sala) {
            foreach ($sala->fechas as $fecha) {
                $dias[$fecha->toDateString()] = $fecha;
            }
        }
        ksort($dias);

        $vueltas = [];
        $anterior = null;
        foreach ($dias as $fecha) {
            if ($anterior === null || $anterior->diffInDays($fecha) > self::HUECO_VUELTA_DIAS) {
                $vueltas[] = $fecha;
            }
            $anterior = $fecha;
        }

        return new Ritmo($vueltas);
    }
}
