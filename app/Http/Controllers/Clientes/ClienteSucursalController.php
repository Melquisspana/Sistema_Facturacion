<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Facturacion\TiendaNuevaController;
use App\Http\Requests\Clientes\ClienteSucursalRequest;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Pais;
use App\Services\Rutas\PedidosSala;
use App\Support\Ubicacion\OpcionesUbicacion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sucursales / salas de un cliente. La autorización reutiliza ClientePolicy:
 * gestionar sucursales requiere poder actualizar el cliente.
 */
class ClienteSucursalController extends Controller
{
    use AuthorizesRequests;

    /** Teléfono sugerido al crear una sala (editable). Solo aplica al alta. */
    private const TELEFONO_POR_DEFECTO = '77777777';

    public function create(Request $request, Cliente $cliente, PedidosSala $pedidos): View
    {
        $this->authorize('update', $cliente);
        // La tienda nueva de un pedido: su nombre, y al guardar queda enlazada.
        $pedidoSala = $pedidos->tiendaNuevaPorEnlazar($request->query('pedido_sala'), $request->user());

        return view('clientes.sucursales.form', $this->datosFormulario(
            $cliente,
            new ClienteSucursal(['activo' => true, 'telefono' => self::TELEFONO_POR_DEFECTO, 'nombre' => $pedidoSala?->sala_nueva_nombre])
        ) + ['pedidoSala' => $pedidoSala]);
    }

    public function store(ClienteSucursalRequest $request, Cliente $cliente, PedidosSala $pedidos): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $datos = $request->validated();
        $datos['pais_id'] = Pais::where('codigo', 'SV')->value('id'); // El Salvador
        $sucursal = $cliente->sucursales()->create($datos);

        // «Dar de alta la tienda» desde Facturación: la sala queda enlazada al pedido y se
        // vuelve a facturar. Si el enlace no se puede (otro ya lo enlazó), la sala igual queda.
        $pedidoSala = $pedidos->tiendaNuevaPorEnlazar($request->input('pedido_sala'), $request->user());
        if ($pedidoSala !== null) {
            try {
                $pedidoSala = $pedidos->enlazarSala($pedidoSala, $sucursal->id, $request->user());

                return redirect()->route('facturacion.create-ccf', ['pedido' => $pedidoSala->id])
                    ->with('status', TiendaNuevaController::listo($pedidoSala));
            } catch (ValidationException $e) {
                return redirect()->route('clientes.show', $cliente)
                    ->with('error', 'Sala «'.$sucursal->nombre.'» creada, pero no se enlazó al pedido: '.collect($e->errors())->flatten()->implode(' '))
                    ->with('sala_destacada', $sucursal->id);
            }
        }

        // Se vuelve a la ficha con la sala nombrada en el mensaje y señalada en la
        // tabla: con muchas salas, un «creada correctamente» genérico obliga a
        // buscarla a ojo para comprobar que quedó como se quería.
        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Sala «'.$sucursal->nombre.'» creada correctamente.')
            ->with('sala_destacada', $sucursal->id);
    }

    public function edit(Cliente $cliente, ClienteSucursal $sucursal): View
    {
        $this->authorize('update', $cliente);
        $this->verificarPertenencia($cliente, $sucursal);

        return view('clientes.sucursales.form', $this->datosFormulario($cliente, $sucursal));
    }

    public function update(ClienteSucursalRequest $request, Cliente $cliente, ClienteSucursal $sucursal): RedirectResponse
    {
        $this->authorize('update', $cliente);
        $this->verificarPertenencia($cliente, $sucursal);

        $sucursal->update($request->validated());

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Sala «'.$sucursal->nombre.'» actualizada correctamente.')
            ->with('sala_destacada', $sucursal->id);
    }

    public function toggleActivo(Cliente $cliente, ClienteSucursal $sucursal): RedirectResponse
    {
        $this->authorize('update', $cliente);
        $this->verificarPertenencia($cliente, $sucursal);

        $sucursal->update(['activo' => ! $sucursal->activo]);

        return back()->with('status', $sucursal->activo ? 'Sala activada.' : 'Sala inactivada.');
    }

    public function destroy(Cliente $cliente, ClienteSucursal $sucursal): RedirectResponse
    {
        $this->authorize('update', $cliente);
        $this->verificarPertenencia($cliente, $sucursal);

        $sucursal->delete(); // soft delete

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Sala eliminada.');
    }

    private function verificarPertenencia(Cliente $cliente, ClienteSucursal $sucursal): void
    {
        abort_unless($sucursal->cliente_id === $cliente->id, 404);
    }

    /** @return array<string, mixed> */
    private function datosFormulario(Cliente $cliente, ClienteSucursal $sucursal): array
    {
        return array_merge([
            'cliente' => $cliente,
            'sucursal' => $sucursal,
        ], OpcionesUbicacion::todas());
    }
}
