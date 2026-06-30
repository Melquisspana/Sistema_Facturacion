<?php

namespace App\Http\Controllers\Clientes;

use App\Enums\TamanioContribuyente;
use App\Enums\TipoCliente;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoPersona;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clientes\ClienteRequest;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Pais;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClienteController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Cliente::class);

        $busqueda = trim((string) $request->input('q', ''));
        $tipoCliente = $request->input('tipo_cliente');
        $activo = $request->input('activo');

        $clientes = Cliente::query()
            ->with(['departamento', 'municipio', 'pais'])
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $query->where(function ($w) use ($busqueda) {
                    $w->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('num_documento', 'like', "%{$busqueda}%")
                        ->orWhere('nrc', 'like', "%{$busqueda}%")
                        ->orWhere('correo', 'like', "%{$busqueda}%");
                });
            })
            ->when(TipoCliente::tryFrom((string) $tipoCliente), fn ($q) => $q->where('tipo_cliente', $tipoCliente))
            ->when($activo === '1', fn ($q) => $q->where('activo', true))
            ->when($activo === '0', fn ($q) => $q->where('activo', false))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('clientes.index', [
            'clientes' => $clientes,
            'filtros' => ['q' => $busqueda, 'tipo_cliente' => $tipoCliente, 'activo' => $activo],
            'tiposCliente' => TipoCliente::opciones(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Cliente::class);

        return view('clientes.form', $this->datosFormulario(new Cliente(['activo' => true])));
    }

    public function store(ClienteRequest $request): RedirectResponse
    {
        $this->authorize('create', Cliente::class);

        $cliente = Cliente::create($request->validated());

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Cliente creado correctamente.');
    }

    public function show(Cliente $cliente): View
    {
        $this->authorize('view', $cliente);

        $cliente->load(['actividadEconomica', 'pais', 'departamento', 'municipio']);
        $cliente->load(['sucursales' => fn ($q) => $q->orderBy('nombre')]);

        $actividades = $cliente->activities()->with('causer')->latest()->limit(30)->get();

        return view('clientes.show', compact('cliente', 'actividades'));
    }

    public function edit(Cliente $cliente): View
    {
        $this->authorize('update', $cliente);

        return view('clientes.form', $this->datosFormulario($cliente));
    }

    public function update(ClienteRequest $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $cliente->update($request->validated());

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Cliente actualizado correctamente.');
    }

    /** Activa o inactiva el cliente (queda registrado en auditoría). */
    public function toggleActivo(Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $cliente->update(['activo' => ! $cliente->activo]);

        return back()->with('status', $cliente->activo ? 'Cliente activado.' : 'Cliente inactivado.');
    }

    public function destroy(Cliente $cliente): RedirectResponse
    {
        $this->authorize('delete', $cliente);

        $cliente->delete(); // soft delete

        return redirect()
            ->route('clientes.index')
            ->with('status', 'Cliente eliminado.');
    }

    private function datosFormulario(Cliente $cliente): array
    {
        return [
            'cliente' => $cliente,
            'tiposCliente' => TipoCliente::opciones(),
            'tiposPersona' => TipoPersona::opciones(),
            'tiposDocumento' => TipoDocumentoCliente::opciones(),
            'tamaniosContribuyente' => TamanioContribuyente::opciones(),
            'actividades' => ActividadEconomica::where('activo', true)->orderBy('nombre')->get(),
            'paises' => Pais::where('activo', true)->orderBy('nombre')->get(),
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(),
            'municipios' => Municipio::where('activo', true)->orderBy('nombre')->get(),
            'distritos' => \App\Models\Distrito::where('activo', true)->orderBy('municipio')->orderBy('nombre')
                ->get(['id', 'nombre', 'municipio', 'departamento_id']),
            // Para preseleccionar El Salvador en clientes nacionales (CAT-020: 9300).
            'paisElSalvadorId' => Pais::where('codigo', '9300')->value('id'),
        ];
    }
}
