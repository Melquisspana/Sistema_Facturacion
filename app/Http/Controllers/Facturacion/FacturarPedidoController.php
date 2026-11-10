<?php

namespace App\Http\Controllers\Facturacion;

use App\Exceptions\Dte\OrdenCompraRequeridaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dte\FacturarPedidoRequest;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Services\Rutas\FacturarPedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * «Crear borrador» con lo que llegó de la fábrica. Ruta aparte de `store-ccf` para que el
 * alta normal del CCF no ejecute nada nuevo. La regla vive en {@see FacturarPedido}.
 */
class FacturarPedidoController extends Controller
{
    public function store(FacturarPedidoRequest $request, PedidoSala $pedido, FacturarPedido $facturar): RedirectResponse
    {
        Gate::authorize('create', Dte::class);
        Gate::authorize('view', $pedido);

        try {
            $dte = $facturar->crear($pedido, $request->validated(), $request->user());
        } catch (OrdenCompraRequeridaException $e) {
            return back()->withInput()->withErrors(['numero_orden_compra' => $e->getMessage()]);
        }

        return redirect()
            ->route('facturacion.edit', $dte)
            ->with('status', 'Borrador CCF creado con lo que llegó de la fábrica. Revisalo con la OC y bajá lo que no pide.');
    }
}
