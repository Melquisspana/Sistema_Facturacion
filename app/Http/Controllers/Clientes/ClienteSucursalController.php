<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clientes\ClienteSucursalRequest;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Pais;
use App\Support\Ubicacion\OpcionesUbicacion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
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

    public function create(Cliente $cliente): View
    {
        $this->authorize('update', $cliente);

        return view('clientes.sucursales.form', $this->datosFormulario(
            $cliente,
            new ClienteSucursal(['activo' => true, 'telefono' => self::TELEFONO_POR_DEFECTO])
        ));
    }

    public function store(ClienteSucursalRequest $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $datos = $request->validated();
        $datos['pais_id'] = Pais::where('codigo', 'SV')->value('id'); // El Salvador
        $sucursal = $cliente->sucursales()->create($datos);

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
