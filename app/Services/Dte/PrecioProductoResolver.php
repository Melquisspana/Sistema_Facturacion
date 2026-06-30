<?php

namespace App\Services\Dte;

use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use Illuminate\Support\Carbon;

/**
 * Resuelve el precio de un producto según cliente/sucursal.
 *
 * Prioridad:
 *   1. precio producto + sucursal
 *   2. precio producto + cliente
 *   3. precio_unitario del producto (general)
 *
 * Solo considera precios activos y dentro de su vigencia (fecha_inicio/fecha_fin).
 * El precio devuelto se congela como snapshot en la línea del DTE.
 */
class PrecioProductoResolver
{
    /** Devuelve el precio aplicable como cadena decimal. */
    public function resolver(Producto $producto, ?int $clienteId = null, ?int $sucursalId = null, ?Carbon $fecha = null): string
    {
        return (string) ($this->resolverConOrigen($producto, $clienteId, $sucursalId, $fecha)['precio']
            ?? $producto->precio_unitario);
    }

    /**
     * Igual que {@see resolver()} pero indica el ORIGEN del precio:
     *   - 'sucursal' / 'cliente': precio especial activo y vigente.
     *   - 'general': precio_unitario del producto (puede ser null si no tiene).
     *
     * Útil para el catálogo del borrador: distinguir precio especial vs. general
     * y detectar productos sin precio aplicable.
     *
     * @return array{precio: ?string, origen: string}
     */
    public function resolverConOrigen(Producto $producto, ?int $clienteId = null, ?int $sucursalId = null, ?Carbon $fecha = null): array
    {
        $fecha ??= Carbon::today();

        if ($sucursalId !== null) {
            $precio = $this->buscarPorSucursal($producto->id, $sucursalId, $fecha);
            if ($precio !== null) {
                return ['precio' => $precio, 'origen' => 'sucursal'];
            }
        }

        if ($clienteId !== null) {
            $precio = $this->buscarPorCliente($producto->id, $clienteId, $fecha);
            if ($precio !== null) {
                return ['precio' => $precio, 'origen' => 'cliente'];
            }
        }

        $general = $producto->precio_unitario;

        return ['precio' => $general !== null ? (string) $general : null, 'origen' => 'general'];
    }

    private function buscarPorSucursal(int $productoId, int $sucursalId, Carbon $fecha): ?string
    {
        return $this->precioVigente(
            ProductoPrecioCliente::query()
                ->where('producto_id', $productoId)
                ->where('cliente_sucursal_id', $sucursalId),
            $fecha
        );
    }

    private function buscarPorCliente(int $productoId, int $clienteId, Carbon $fecha): ?string
    {
        return $this->precioVigente(
            ProductoPrecioCliente::query()
                ->where('producto_id', $productoId)
                ->where('cliente_id', $clienteId)
                ->whereNull('cliente_sucursal_id'),
            $fecha
        );
    }

    private function precioVigente(\Illuminate\Database\Eloquent\Builder $query, Carbon $fecha): ?string
    {
        $precio = $query
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('fecha_inicio')->orWhere('fecha_inicio', '<=', $fecha))
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $fecha))
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->value('precio');

        return $precio !== null ? (string) $precio : null;
    }
}
