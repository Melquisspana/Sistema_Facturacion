<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clientes\ClienteSucursalRequest;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Municipio;
use App\Models\Pais;
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

    public function create(Cliente $cliente): View
    {
        $this->authorize('update', $cliente);

        return view('clientes.sucursales.form', $this->datosFormulario($cliente, new ClienteSucursal(['activo' => true])));
    }

    public function store(ClienteSucursalRequest $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $datos = $request->validated();
        $datos['pais_id'] = Pais::where('codigo', '9300')->value('id'); // El Salvador
        $cliente->sucursales()->create($datos);

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Sucursal creada correctamente.');
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
            ->with('status', 'Sucursal actualizada correctamente.');
    }

    public function toggleActivo(Cliente $cliente, ClienteSucursal $sucursal): RedirectResponse
    {
        $this->authorize('update', $cliente);
        $this->verificarPertenencia($cliente, $sucursal);

        $sucursal->update(['activo' => ! $sucursal->activo]);

        return back()->with('status', $sucursal->activo ? 'Sucursal activada.' : 'Sucursal inactivada.');
    }

    public function destroy(Cliente $cliente, ClienteSucursal $sucursal): RedirectResponse
    {
        $this->authorize('update', $cliente);
        $this->verificarPertenencia($cliente, $sucursal);

        $sucursal->delete(); // soft delete

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Sucursal eliminada.');
    }

    private function verificarPertenencia(Cliente $cliente, ClienteSucursal $sucursal): void
    {
        abort_unless($sucursal->cliente_id === $cliente->id, 404);
    }

    /** @return array<string, mixed> */
    private function datosFormulario(Cliente $cliente, ClienteSucursal $sucursal): array
    {
        return [
            'cliente' => $cliente,
            'sucursal' => $sucursal,
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(),
            'municipios' => Municipio::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            'distritos' => Distrito::where('activo', true)->orderBy('municipio')->orderBy('nombre')
                ->get(['id', 'nombre', 'municipio', 'departamento_id']),
        ];
    }
}
