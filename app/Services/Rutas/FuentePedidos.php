<?php

namespace App\Services\Rutas;

use Carbon\CarbonImmutable;

/**
 * De dónde salen las fechas de pedido de cada sala para calcular su ritmo
 * ({@see RitmoPedidos}). Hoy son los CCF ({@see FuentePedidosCcf}); cuando existan los
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
}
