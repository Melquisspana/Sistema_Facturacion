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
use Illuminate\Support\Collection;
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
            // Los otros pedidos de la misma tienda nueva: el enlace los lleva a todos.
            'mismaTienda' => PedidosSala::mismaTiendaNueva($pedido),
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

        $enlace = $pedidos->enlazarTiendaNueva($pedido, $salaId, $request->user());

        return redirect()->route('facturacion.create-ccf', ['pedido' => $enlace['pedido']->id])
            ->with('status', self::listo($enlace));
    }

    /**
     * El aviso al volver a facturar, también desde el alta de la sala en Clientes, con lo que
     * devuelve {@see PedidosSala::enlazarTiendaNueva()}: los otros pedidos de la tienda que se
     * enlazaron con él y los que quedaron afuera, con el motivo. Si la sala ya tenía otro
     * pedido vigente ese día (fuera de los de esta tienda), lo dice: dos vendedores pudieron
     * escribir la misma tienda con nombres distintos. Solo avisa; facturar o no, lo decide la
     * oficina.
     *
     * @param  array{pedido: PedidoSala, enlazados: Collection<int, PedidoSala>, afuera: list<string>}  $enlace
     */
    public static function listo(array $enlace): string
    {
        ['pedido' => $pedido, 'enlazados' => $enlazados, 'afuera' => $afuera] = $enlace;
        $otro = PedidoSala::vigentes()->where('cliente_sucursal_id', $pedido->cliente_sucursal_id)
            ->whereKeyNot($enlazados->pluck('id')->push($pedido->id)->all())
            ->whereDate('fecha', $pedido->fecha)->with('sacadoPor:id,nombre')->orderBy('id')->first();
        $n = $enlazados->count();

        return 'Listo: el pedido de «'.$pedido->sala_nueva_nombre.'» quedó enlazado a la sala «'.$pedido->sala?->nombre.'». Ya podés facturarlo.'
            .($n === 0 ? '' : ($n === 1 ? ' También quedó enlazado 1 pedido más' : ' También quedaron enlazados '.$n.' pedidos más')
                .' de «'.$pedido->sala_nueva_nombre.'»: N.º '.$enlazados->pluck('id')->join(', ', ' y ').'.')
            .($afuera === [] ? '' : ' No se enlazaron: '.implode(' ', $afuera).' Revisalos en Pedidos.')
            .($otro === null ? '' : ' Ojo: «'.$pedido->sala?->nombre.'» ya tiene otro pedido del '.$pedido->fecha->format('d/m/Y')
                .($otro->sacadoPor ? ', de '.$otro->sacadoPor->nombre : '').'. Revisá que no sea el mismo antes de facturar los dos.');
    }
}
