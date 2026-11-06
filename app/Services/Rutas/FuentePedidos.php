<?php

namespace App\Services\Rutas;

use Carbon\CarbonImmutable;

/**
 * De dónde salen los pedidos de cada sala: sus fechas, para calcular el ritmo
 * ({@see RitmoPedidos}), y qué productos y cuántos, para el pedido estimado
 * ({@see EstimadoPedidos}). Hoy son los CCF ({@see FuentePedidosCcf}); cuando existan los
 * pedidos reales (etapa 3 de docs/PLAN_TABLERO_RUTAS.md) se cambia la fuente y el
 * cálculo y el tablero no se tocan.
 */
interface FuentePedidos
{
    /**
     * Fechas en que cada sala hizo un pedido entre `$desde` y `$hasta`, sin repetir el
     * mismo día. Una sala sin pedidos no aparece.
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, array<int, CarbonImmutable>> sala_id => fechas en orden
     */
    public function fechasPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array;

    /**
     * Qué productos y cuántos pidió cada sala cada día entre `$desde` y `$hasta`. Los días
     * son los mismos de {@see fechasPorSala()}; varios documentos del mismo día se suman.
     * Un día sin ningún producto enlazado no aparece (sigue contando como pedido por su
     * fecha). Las cantidades pueden tener hasta 4 decimales.
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, array<string, array<int, float>>> sala_id => ['Y-m-d' => [producto_id => cantidad]], días en orden
     */
    public function productosPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array;
}
