<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\PedidoSalaRequest;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\SalidaRuta;
use App\Services\Rutas\PedidosSala;
use App\Support\Dte\ReglaOrdenCompra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * «Sacar pedido» desde «Mi salida»: el formulario de una sala, guardarlo, corregirlo y
 * anularlo. La regla está en {@see PedidosSala}; acá solo se arma la pantalla.
 */
class MiPedidoController extends Controller
{
    public function __construct(private readonly PedidosSala $pedidos) {}

    public function create(Request $request, SalidaRuta $salida, ClienteSucursal $sucursal): View|RedirectResponse
    {
        Gate::authorize('sacarPedido', $salida);
        abort_unless($this->pedidos->salasDeSalida($salida)->whereKey($sucursal->id)->exists(), 404);

        // Ya sacado: no se saca otro. Quien lo sacó lo corrige; el compañero vuelve a la sala.
        $sacado = $salida->pedidos()->registrados()->where('cliente_sucursal_id', $sucursal->id)->first();
        if ($sacado) {
            return $request->user()->can('corregir', $sacado)
                ? redirect()->route('rutas.mi-salida.pedidos.edit', [$salida, $sacado])
                : redirect()->to(route('rutas.mi-salida.show', $salida).'#sala-'.$sucursal->id)
                    ->with('error', 'El pedido de «'.$sucursal->nombre.'» ya está sacado.');
        }

        return $this->formulario($salida, $sucursal, null);
    }

    public function store(PedidoSalaRequest $request, SalidaRuta $salida, ClienteSucursal $sucursal): RedirectResponse
    {
        $pedido = $this->pedidos->guardar($salida, $sucursal, $request->validated(), $request->user());

        return $this->volver($salida, $pedido, 'Pedido de «'.$sucursal->nombre.'» guardado.');
    }

    public function edit(SalidaRuta $salida, PedidoSala $pedido): View
    {
        Gate::authorize('corregir', $pedido);

        return $this->formulario($salida, $pedido->sala, $pedido);
    }

    public function update(PedidoSalaRequest $request, SalidaRuta $salida, PedidoSala $pedido): RedirectResponse
    {
        $pedido = $this->pedidos->guardar($salida, $pedido->sala, $request->validated(), $request->user(), $pedido);

        return $this->volver($salida, $pedido, 'Pedido de «'.$pedido->sala->nombre.'» corregido.');
    }

    public function anular(Request $request, SalidaRuta $salida, PedidoSala $pedido): RedirectResponse
    {
        $this->pedidos->anular($salida, $pedido, $request->user());

        return redirect()->to(route('rutas.mi-salida.show', $salida).'#sala-'.$pedido->cliente_sucursal_id)
            ->with('status', 'Pedido de «'.$pedido->sala->nombre.'» anulado. Si hace falta, sacalo de nuevo.');
    }

    private function formulario(SalidaRuta $salida, ClienteSucursal $sala, ?PedidoSala $pedido): View
    {
        $sala->loadMissing('cliente:id,requiere_orden_compra');
        $pedido?->load('lineas');

        return view('rutas.mi-salida.pedido', [
            'salida' => $salida->loadMissing('ruta'),
            'sala' => $sala,
            'pedido' => $pedido,
            'productos' => $this->pedidos->productos(),
            'cantidades' => $pedido ? $pedido->lineas->mapWithKeys(fn ($l) => [$l->producto_id => $l->cantidadLegible()])->all() : [],
            // La OC solo si la sala la usa (la misma regla de facturación) o si ya se anotó una.
            'conOc' => ReglaOrdenCompra::requerida($sala->cliente, $sala) || filled($pedido?->numero_orden_compra),
        ]);
    }

    /** A la sala en «Mi salida», con el aviso de la OC si su forma no cuadra (no bloquea). */
    private function volver(SalidaRuta $salida, PedidoSala $pedido, string $mensaje): RedirectResponse
    {
        $avisos = $pedido->avisosOrdenCompra();

        return redirect()->to(route('rutas.mi-salida.show', $salida).'#sala-'.$pedido->cliente_sucursal_id)
            ->with('status', trim($mensaje.($avisos ? ' Revisá la orden de compra: '.implode(' ', $avisos) : '')));
    }
}
