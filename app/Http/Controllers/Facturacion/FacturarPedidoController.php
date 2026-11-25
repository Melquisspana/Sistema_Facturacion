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
use Illuminate\Validation\ValidationException;

/**
 * «Crear borrador» con lo que llegó de la fábrica. Ruta aparte de `store-ccf` para que el
 * alta normal del CCF no ejecute nada nuevo. La regla vive en {@see FacturarPedido}.
 *
 * Desde el 24/11/2026 también la usa el «Facturar» directo de «Llegó de pedidos» (`directo=1`,
 * con la única OC clara): si algo frena, en vez de volver a la lista con un error suelto, abre
 * el formulario del pedido, que dice qué falta.
 */
class FacturarPedidoController extends Controller
{
    public function store(FacturarPedidoRequest $request, PedidoSala $pedido, FacturarPedido $facturar): RedirectResponse
    {
        Gate::authorize('create', Dte::class);
        Gate::authorize('view', $pedido);
        $volver = fn () => $request->esDirecto() ? redirect()->to($request->formulario()) : back();

        try {
            $dte = $facturar->crear($pedido, $request->validated(), $request->user());
        } catch (OrdenCompraRequeridaException $e) {
            return $volver()->withInput()->withErrors(['numero_orden_compra' => $e->getMessage()]);
        } catch (ValidationException $e) {
            if (! $request->esDirecto()) {
                throw $e;
            }

            return $volver()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('facturacion.edit', $dte)
            ->with('status', 'Borrador CCF creado con lo que llegó de pedidos. Revisalo con la OC y bajá lo que no pide.');
    }
}
