<?php

namespace App\Services\Rutas;

use Carbon\CarbonImmutable;

/**
 * El pedido estimado de cada sala (etapa 4 de docs/PLAN_TABLERO_RUTAS.md), con los
 * pedidos que da la {@see FuentePedidos} (hoy, los CCF):
 *
 *  · Se miran sus últimos 6 pedidos. Un pedido es el mismo que cuenta el ritmo
 *    ({@see RitmoPedidos::deSala()}): los documentos del mismo día o del día siguiente
 *    son uno solo, y sus cantidades se suman.
 *  · Un producto entra si apareció en al menos la mitad de esos pedidos.
 *  · La cantidad es la mediana de lo que llevó las veces que apareció.
 *  · Con menos de 3 pedidos se marca «poco dato» y se devuelve lo que haya.
 */
class EstimadoPedidos
{
    public const PEDIDOS = 6;

    /** Las cantidades se cuentan en diezmilésimas: los 4 decimales de la línea, sin errores de redondeo. */
    private const ESCALA = 10000;

    public function __construct(
        private readonly RitmoPedidos $ritmos,
        private readonly FuentePedidos $fuente,
    ) {}

    /**
     * Dos pedidos a la fuente para todas las salas: las fechas y los productos desde el
     * más viejo de los últimos 6 pedidos.
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, PedidoEstimado> sala_id => estimado; también las que no tienen pedidos
     */
    public function porSala(array $salaIds, ?CarbonImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->startOfDay();

        $comienzos = [];
        foreach ($this->ritmos->porSala($salaIds, $hoy) as $id => $ritmo) {
            $comienzos[$id] = array_slice($ritmo->fechas, -self::PEDIDOS);
        }

        $conPedidos = array_filter($comienzos);
        $productos = $conPedidos === [] ? [] : $this->fuente->productosPorSala(
            array_keys($conPedidos),
            min(array_map(fn ($fechas) => $fechas[0], $conPedidos)),
            $hoy,
        );

        $estimados = [];
        foreach ($salaIds as $id) {
            $estimados[$id] = $this->deSala($comienzos[$id] ?? [], $productos[$id] ?? []);
        }

        return $estimados;
    }

    public function paraSala(int $salaId, ?CarbonImmutable $hoy = null): PedidoEstimado
    {
        return $this->porSala([$salaId], $hoy)[$salaId];
    }

    /**
     * @param  array<int, CarbonImmutable>  $comienzos  el día en que empieza cada pedido, en orden
     * @param  array<string, array<int, float|int|string>>  $dias  'Y-m-d' => [producto_id => cantidad]
     */
    public function deSala(array $comienzos, array $dias): PedidoEstimado
    {
        $comienzos = array_values(array_slice($comienzos, -self::PEDIDOS));
        $total = count($comienzos);

        // producto_id => [pedido => cantidad]; cada día suma en el pedido que empezó antes.
        $cantidades = [];
        foreach ($dias as $dia => $productos) {
            $fecha = CarbonImmutable::parse($dia)->startOfDay();
            $pedido = null;
            foreach ($comienzos as $i => $comienzo) {
                if ($comienzo->lessThanOrEqualTo($fecha)) {
                    $pedido = $i;
                }
            }
            if ($pedido === null) {
                continue;
            }
            foreach ($productos as $productoId => $cantidad) {
                $cantidades[$productoId][$pedido] = ($cantidades[$productoId][$pedido] ?? 0)
                    + (int) round((float) $cantidad * self::ESCALA);
            }
        }

        $lineas = [];
        foreach ($cantidades as $productoId => $porPedido) {
            $porPedido = array_filter($porPedido, fn ($cantidad) => $cantidad > 0);
            $veces = count($porPedido);
            if ($veces === 0 || $veces * 2 < $total) {
                continue;
            }
            ksort($porPedido);
            $lineas[] = [
                'producto_id' => (int) $productoId,
                'cantidad' => self::decimal(self::mediana($porPedido)),
                'veces' => $veces,
                'ultima_cantidad' => self::decimal(end($porPedido)),
            ];
        }
        usort($lineas, fn ($a, $b) => $b['veces'] <=> $a['veces'] ?: $a['producto_id'] <=> $b['producto_id']);

        return new PedidoEstimado($lineas, $total, $comienzos[$total - 1] ?? null);
    }

    /**
     * El del medio; con dos en el medio, su promedio. Si ese promedio cae entre dos
     * diezmilésimas, se redondea hacia arriba.
     *
     * @param  array<int, int>  $valores  no vacío, positivos
     */
    private static function mediana(array $valores): int
    {
        sort($valores);
        $medio = intdiv(count($valores), 2);

        return count($valores) % 2 === 1
            ? $valores[$medio]
            : intdiv($valores[$medio - 1] + $valores[$medio] + 1, 2);
    }

    private static function decimal(int $diezmilesimas): string
    {
        return number_format($diezmilesimas / self::ESCALA, 4, '.', '');
    }
}
