<?php

namespace App\Http\Controllers\Clientes;

use App\Enums\CondicionPago;
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

        // El contacto (teléfono/correo) se captura principalmente en la sala; el
        // cliente no lo precarga. El teléfono sugerido 77777777 vive en el alta de
        // sala (ver ClienteSucursalController).
        return view('clientes.form', $this->datosFormulario(new Cliente(['activo' => true])));
    }

    public function store(ClienteRequest $request): RedirectResponse
    {
        $this->authorize('create', Cliente::class);

        $cliente = Cliente::create($request->validated());

        // "Guardar y agregar primera sala": encadena el alta con el formulario de
        // sucursal, que es donde vive la ubicación operativa del cliente.
        if ($request->input('accion') === 'guardar_y_sala') {
            return redirect()
                ->route('clientes.sucursales.create', $cliente)
                ->with('status', 'Cliente creado. Agregue la primera sala.');
        }

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
            // Un cliente ya guardado que todavía no tiene salas muestra su bloque de
            // ubicación abierto (aunque esté vacío). NO implica marcar "sin salas":
            // esa sigue siendo una decisión explícita del usuario.
            'clienteSinSalas' => $cliente->exists && $cliente->sucursales()->doesntExist(),
            // Compatibilidad: un cliente antiguo que ya tiene contacto u orden de
            // compra propios los sigue mostrando, aunque el alta nueva los oculte.
            'clienteTieneContacto' => $cliente->exists && (filled($cliente->telefono) || filled($cliente->correo)),
            'clienteTieneOc' => $cliente->exists && (bool) $cliente->requiere_orden_compra,
            'tiposCliente' => TipoCliente::opciones(),
            'tiposPersona' => TipoPersona::opciones(),
            'tiposDocumento' => TipoDocumentoCliente::opciones(),
            'tamaniosContribuyente' => TamanioContribuyente::opciones(),
            // CAT-016 para el select de condición de pago por defecto del cliente.
            'condicionesPago' => collect(CondicionPago::cases())
                ->mapWithKeys(fn (CondicionPago $c) => [$c->value => $c->label()])
                ->all(),
            'actividades' => ActividadEconomica::where('activo', true)->orderBy('nombre')->get(),
            'paises' => Pais::where('activo', true)->orderBy('nombre')->get(),
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(),
            'municipios' => Municipio::where('activo', true)->orderBy('nombre')->get(),
            'distritos' => \App\Models\Distrito::where('activo', true)->orderBy('municipio')->orderBy('nombre')
                ->get(['id', 'nombre', 'municipio', 'departamento_id']),
            // Para preseleccionar El Salvador en clientes nacionales (CAT-020: SV).
            'paisElSalvadorId' => Pais::where('codigo', 'SV')->value('id'),
        ];
    }
}
