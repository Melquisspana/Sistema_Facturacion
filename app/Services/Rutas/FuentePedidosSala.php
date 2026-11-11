<?php

namespace App\Services\Rutas;

use App\Models\PedidoSala;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La fuente de los pedidos (etapa 5 del flujo de Rutas, 10/11/2026): los PEDIDOS de sala
 * que sacan los vendedores, completados con los CCF que no salieron de un pedido
 * ({@see FuentePedidosCcf}). Así sigue funcionando mientras casi no haya pedidos.
 *
 * Un pedido y el CCF que se le facturó son la misma visita: cuenta el pedido, con su fecha
 * y sus líneas, y ese CCF ya no viene de la otra fuente. Un pedido anulado no cuenta.
 */
class FuentePedidosSala implements FuentePedidos
{
    public function __construct(private readonly FuentePedidosCcf $ccf) {}

    public function fechasPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        if ($salaIds === []) {
            return [];
        }

        $fechas = [];
        $sumar = function (int $salaId, CarbonImmutable $dia) use (&$fechas) {
            $fechas[$salaId][$dia->toDateString()] = $dia;
        };

        // Una consulta para todas las salas: un par sala/día por fila.
        $this->pedidos($salaIds, $desde, $hasta)
            ->distinct()
            ->get(['cliente_sucursal_id', 'fecha'])
            ->each(fn ($fila) => $sumar((int) $fila->cliente_sucursal_id, CarbonImmutable::parse($fila->fecha)->startOfDay()));

        foreach ($this->ccf->fechasPorSala($salaIds, $desde, $hasta) as $salaId => $dias) {
            foreach ($dias as $dia) {
                $sumar($salaId, $dia);
            }
        }

        foreach ($fechas as $salaId => $dias) {
            ksort($dias);
            $fechas[$salaId] = array_values($dias);
        }

        return $fechas;
    }

    /** Las líneas del pedido (lo que la sala pidió) y las de los CCF sin pedido, sumadas por día. */
    public function productosPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        if ($salaIds === []) {
            return [];
        }

        $productos = $this->ccf->productosPorSala($salaIds, $desde, $hasta);

        DB::table('pedido_sala_lineas as linea')
            ->joinSub($this->pedidos($salaIds, $desde, $hasta)->toBase()->select('id', 'cliente_sucursal_id', 'fecha'), 'pedido', 'pedido.id', '=', 'linea.pedido_sala_id')
            ->groupBy('pedido.cliente_sucursal_id', 'pedido.fecha', 'linea.producto_id')
            ->selectRaw('pedido.cliente_sucursal_id as sala_id, pedido.fecha, linea.producto_id, SUM(linea.cantidad) as cantidad')
            ->get()
            ->each(function ($fila) use (&$productos) {
                $dia = CarbonImmutable::parse($fila->fecha)->toDateString();
                $actual = $productos[$fila->sala_id][$dia][$fila->producto_id] ?? 0.0;
                $productos[$fila->sala_id][$dia][$fila->producto_id] = $actual + (float) $fila->cantidad;
            });

        foreach ($productos as $salaId => $dias) {
            ksort($dias);
            $productos[$salaId] = $dias;
        }

        return $productos;
    }

    /** @param  array<int, int>  $salaIds */
    private function pedidos(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): Builder
    {
        return PedidoSala::vigentes()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString());
    }
}
