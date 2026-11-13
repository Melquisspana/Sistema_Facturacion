<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Clientes\ClienteSucursalController;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Services\Rutas\PedidosSala;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * La tienda nueva al facturar (Melqui, 10/11/2026): el vendedor anotó solo el nombre; acá la
 * oficina la enlaza a su sala. Si ya existe, la busca y la enlaza; si no, la da de alta con
 * el formulario de Clientes, que vuelve enlazando ({@see ClienteSucursalController::store()}).
 * La regla del enlace está en {@see PedidosSala::enlazarSala()}.
 */
class TiendaNuevaController extends Controller
{
    public function show(Request $request, PedidoSala $pedido): View|RedirectResponse
    {
        Gate::authorize('enlazarSala', $pedido);
        if (! $pedido->esTiendaNueva()) {
            return redirect()->route('facturacion.create-ccf', ['pedido' => $pedido->id])
                ->with('status', 'Este pedido ya tiene su sala: «'.$pedido->sala?->nombre.'».');
        }

        $texto = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? $pedido->sala_nueva_nombre));
        $salas = mb_strlen($texto) >= 2
            ? ClienteSucursal::query()->buscarTexto($texto)->with(['cliente:id,nombre', 'distrito:id,nombre'])->orderBy('nombre')->limit(20)->get()
            : collect();

        return view('facturacion.tienda-nueva', [
            'pedido' => $pedido->loadMissing(['sacadoPor:id,nombre', 'ruta:id,nombre', 'salida:id,ruta_id', 'salida.ruta:id,nombre']),
            'texto' => $texto,
            'salas' => $salas,
            'puedeDarDeAlta' => $request->user()->can('create', [Cliente::class, $pedido]),
        ]);
    }

    public function enlazar(Request $request, PedidoSala $pedido, PedidosSala $pedidos): RedirectResponse
    {
        Gate::authorize('enlazarSala', $pedido);
        $salaId = (int) $request->validate(
            ['cliente_sucursal_id' => ['required', 'integer']],
            ['cliente_sucursal_id.*' => 'Elegí la sala de la tienda.'],
        )['cliente_sucursal_id'];

        $pedido = $pedidos->enlazarSala($pedido, $salaId, $request->user());

        return redirect()->route('facturacion.create-ccf', ['pedido' => $pedido->id])
            ->with('status', self::listo($pedido));
    }

    /** El aviso al volver a facturar, también desde el alta de la sala en Clientes. */
    public static function listo(PedidoSala $pedido): string
    {
        return 'Listo: el pedido de «'.$pedido->sala_nueva_nombre.'» quedó enlazado a la sala «'.$pedido->sala?->nombre.'». Ya podés facturarlo.';
    }
}
