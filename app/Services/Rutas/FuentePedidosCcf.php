<?php

namespace App\Services\Rutas;

use App\Enums\TipoNotaCredito;
use App\Models\Dte;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        $this->ccfs($salaIds, $desde, $hasta)
            ->distinct()
            ->orderBy('fecha_emision')
            ->get(['cliente_sucursal_id', 'fecha_emision'])
            ->each(function ($fila) use (&$fechas) {
                $dia = CarbonImmutable::parse($fila->fecha_emision)->startOfDay();
                $fechas[$fila->cliente_sucursal_id][$dia->toDateString()] = $dia;
            });

        return array_map('array_values', $fechas);
    }

    /**
     * Las líneas con producto de esos mismos CCF. Las de texto libre (sin producto) no
     * cuentan. Una nota de crédito por devolución de productos, aceptada por Hacienda,
     * resta lo devuelto en el día del CCF que corrige: la sala no se quedó con eso. Las
     * demás notas no restan: el faltante la sala sí lo pidió, la avería no dice nada de
     * cuánto pide y las de monto (pronto pago, descuentos) no llevan cantidades.
     *
     * Dos consultas para todas las salas, sin importar cuántos productos haya.
     */
    public function productosPorSala(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        if ($salaIds === []) {
            return [];
        }

        $ccfIds = $this->ccfs($salaIds, $desde, $hasta)->select('id');
        $productos = [];
        $sumar = function ($fila, int $signo) use (&$productos) {
            $dia = CarbonImmutable::parse($fila->fecha_emision)->toDateString();
            $actual = $productos[$fila->sala_id][$dia][$fila->producto_id] ?? 0.0;
            $productos[$fila->sala_id][$dia][$fila->producto_id] = $actual + $signo * (float) $fila->cantidad;
        };

        DB::table('dte_lineas as linea')
            ->join('dtes', 'dtes.id', '=', 'linea.dte_id')
            ->whereIn('linea.dte_id', $ccfIds)
            ->whereNotNull('linea.producto_id')
            ->groupBy('dtes.cliente_sucursal_id', 'dtes.fecha_emision', 'linea.producto_id')
            ->orderBy('dtes.fecha_emision')
            ->selectRaw('dtes.cliente_sucursal_id as sala_id, dtes.fecha_emision, linea.producto_id, SUM(linea.cantidad) as cantidad')
            ->get()
            ->each(fn ($fila) => $sumar($fila, 1));

        // Como en Ventas: el CCF y el producto salen de la línea original si la NC la enlaza.
        $ccf = 'COALESCE(original.dte_id, nc.dte_relacionado_id)';
        $producto = 'COALESCE(original.producto_id, linea.producto_id)';
        $devoluciones = Dte::query()
            ->where('tipo_dte', '05')
            ->where('tipo_nota_credito', TipoNotaCredito::DevolucionProducto->value)
            ->noArchivados()
            ->aceptadoRealMh()
            ->select('id');

        DB::table('dtes as nc')
            ->join('dte_lineas as linea', 'linea.dte_id', '=', 'nc.id')
            ->leftJoin('dte_lineas as original', 'original.id', '=', 'linea.dte_linea_original_id')
            ->join('dtes as ccf', 'ccf.id', '=', DB::raw($ccf))
            ->whereIn('nc.id', $devoluciones)
            ->whereIn('ccf.id', $ccfIds)
            ->whereColumn('nc.ambiente', 'ccf.ambiente')
            ->whereRaw("{$producto} IS NOT NULL")
            ->selectRaw("ccf.cliente_sucursal_id as sala_id, ccf.fecha_emision, {$producto} as producto_id, linea.cantidad")
            ->get()
            ->each(fn ($fila) => $sumar($fila, -1));

        foreach ($productos as $salaId => $dias) {
            foreach ($dias as $dia => $cantidades) {
                // Lo devuelto entero ya no es parte del pedido.
                $cantidades = array_filter($cantidades, fn ($cantidad) => round($cantidad, 4) > 0);
                if ($cantidades === []) {
                    unset($productos[$salaId][$dia]);
                } else {
                    $productos[$salaId][$dia] = $cantidades;
                }
            }
            ksort($productos[$salaId]);
        }

        return array_filter($productos);
    }

    /** @param  array<int, int>  $salaIds */
    private function ccfs(array $salaIds, CarbonImmutable $desde, CarbonImmutable $hasta): Builder
    {
        return $this->entregas->baseCcf()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->whereDate('fecha_emision', '>=', $desde->toDateString())
            ->whereDate('fecha_emision', '<=', $hasta->toDateString());
    }
}
