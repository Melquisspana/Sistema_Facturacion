<?php

namespace App\Http\Controllers\Productos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Productos\ProductoPrecioRequest;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * Precios especiales de un producto por cliente/sucursal. Gestionar precios
 * requiere poder actualizar el producto (ProductoPolicy::update).
 */
class ProductoPrecioController extends Controller
{
    use AuthorizesRequests;

    public function store(ProductoPrecioRequest $request, Producto $producto): RedirectResponse
    {
        $this->authorize('update', $producto);

        $producto->preciosCliente()->create($request->validated());

        return redirect()
            ->route('productos.show', $producto)
            ->with('status', 'Precio por cliente agregado.');
    }

    public function toggleActivo(Producto $producto, ProductoPrecioCliente $precio): RedirectResponse
    {
        $this->authorize('update', $producto);
        $this->verificarPertenencia($producto, $precio);

        // La misma regla que al agregarlo: con dos activos para el mismo cliente y sala, el
        // CCF toma uno de los dos sin que nadie lo note.
        $otroActivo = ! $precio->activo && ProductoPrecioCliente::query()
            ->where('producto_id', $producto->id)
            ->where('cliente_id', $precio->cliente_id)
            ->where('cliente_sucursal_id', $precio->cliente_sucursal_id)
            ->where('activo', true)
            ->whereKeyNot($precio->id)
            ->exists();
        if ($otroActivo) {
            return back()->withErrors(['precio' => 'No se activó: ya hay otro precio activo para ese cliente y sala. Inactivá ese primero.']);
        }

        $precio->update(['activo' => ! $precio->activo]);

        return back()->with('status', $precio->activo ? 'Precio activado.' : 'Precio inactivado.');
    }

    public function destroy(Producto $producto, ProductoPrecioCliente $precio): RedirectResponse
    {
        $this->authorize('update', $producto);
        $this->verificarPertenencia($producto, $precio);

        $precio->delete();

        return redirect()
            ->route('productos.show', $producto)
            ->with('status', 'Precio eliminado.');
    }

    private function verificarPertenencia(Producto $producto, ProductoPrecioCliente $precio): void
    {
        abort_unless($precio->producto_id === $producto->id, 404);
    }
}
