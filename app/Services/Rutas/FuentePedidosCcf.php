<?php

namespace App\Services\Rutas;

use Carbon\CarbonImmutable;

/**
 * La fuente de hoy: la fecha de emisión de los CCF, que es casi la del pedido. Usa el
 * mismo filtro que las entregas ({@see EntregasCcf::baseCcf()}): aceptados de verdad por
 * Hacienda, sin invalidados ni archivados, del ambiente operativo.
 *
 * Un CCF que se factura días después de la visita corre la fecha unos días; se acepta
 * (decisión 5 del plan): lo absorbe el margen del ritmo.
 */
class FuentePedidosCcf implements FuentePedidos
{
    public function __construct(private readonly EntregasCcf $entregas) {}

    public function fechasPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        if ($salaIds === []) {
            return [];
        }

        $fechas = [];

        // Una sola consulta para todas las salas: un par sala/día por fila.
        $this->entregas->baseCcf()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->whereDate('fecha_emision', '>=', $desde->toDateString())
            ->whereDate('fecha_emision', '<=', $hasta->toDateString())
            ->distinct()
            ->orderBy('fecha_emision')
            ->get(['cliente_sucursal_id', 'fecha_emision'])
            ->each(function ($fila) use (&$fechas) {
                $dia = CarbonImmutable::parse($fila->fecha_emision)->startOfDay();
                $fechas[$fila->cliente_sucursal_id][$dia->toDateString()] = $dia;
            });

        return array_map('array_values', $fechas);
    }
}
