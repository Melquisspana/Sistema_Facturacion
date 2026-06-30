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
