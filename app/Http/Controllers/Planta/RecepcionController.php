<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\ConfirmarRecepcionRequest;
use App\Http\Requests\Planta\RecepcionRequest;
use App\Http\Requests\Planta\ReversarRecepcionRequest;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaProveedor;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Services\Planta\PlantaRecepcionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Recepciones de insumos.
 *
 * Controlador DELGADO: autoriza, delega y traduce el resultado a una
 * redirección. Ni una regla de negocio vive aquí. Confirmar y reversar pasan
 * ENTERAS por {@see PlantaRecepcionService}, que es quien abre la transacción,
 * bloquea el documento y habla con el motor de inventario.
 *
 * Las excepciones de dominio del servicio se traducen a un mensaje de error en
 * la redirección: son situaciones esperables —el documento cambió de estado, la
 * ubicación se desactivó, el saldo ya se movió— y el usuario debe leerlas, no
 * encontrarse un 500.
 */
class RecepcionController extends Controller
{
    public function __construct(private readonly PlantaRecepcionService $servicio) {}

    public function index(Request $request): View
    {
        $recepciones = PlantaRecepcion::query()
            ->with(['proveedor:id,nombre', 'ubicacion:id,codigo,nombre', 'creadoPor:id,name'])
            ->withCount('detalles')
            ->when($request->filled('numero'), fn ($q) => $q->where('numero', $request->integer('numero')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->date('hasta')))
            ->when($request->filled('proveedor'), fn ($q) => $q->where('planta_proveedor_id', $request->integer('proveedor')))
            ->when($request->filled('ubicacion'), fn ($q) => $q->where('planta_ubicacion_id', $request->integer('ubicacion')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('creado_por'), fn ($q) => $q->where('creado_por', $request->integer('creado_por')))
            ->when($request->filled('insumo'), fn ($q) => $q->whereHas(
                'detalles',
                fn ($d) => $d->where('planta_insumo_id', $request->integer('insumo'))
            ))
            ->orderByDesc('numero')
            ->paginate(25)
            ->withQueryString();

        return view('planta.recepciones.index', [
            'recepciones' => $recepciones,
            'estados' => EstadoRecepcionPlanta::cases(),
        ] + $this->opcionesFiltro());
    }

    public function create(): View
    {
        return view('planta.recepciones.create', $this->opcionesFormulario());
    }

    public function store(RecepcionRequest $request): RedirectResponse
    {
        try {
            $recepcion = $this->servicio->crearBorrador($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['recepcion' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.recepciones.show', $recepcion)
            ->with('status', "Recepción #{$recepcion->numero} creada como borrador.");
    }

    public function show(PlantaRecepcion $recepcion): View
    {
        $recepcion->load([
            'detalles.insumo:id,codigo,nombre,unidad_base',
            'detalles.lote:id,codigo_interno,codigo_proveedor',
            'proveedor:id,nombre',
            'ubicacion:id,codigo,nombre',
            'creadoPor:id,name',
            'confirmadoPor:id,name',
            'reversionDe:id,numero',
            'revertidoPor:id,numero',
        ]);

        return view('planta.recepciones.show', ['recepcion' => $recepcion]);
    }

    public function edit(PlantaRecepcion $recepcion): View|RedirectResponse
    {
        if (! $recepcion->esEditable()) {
            return redirect()
                ->route('planta.recepciones.show', $recepcion)
                ->withErrors(['recepcion' => 'Solo se edita un borrador.']);
        }

        $recepcion->load('detalles');

        return view('planta.recepciones.edit', ['recepcion' => $recepcion] + $this->opcionesFormulario());
    }

    public function update(RecepcionRequest $request, PlantaRecepcion $recepcion): RedirectResponse
    {
        try {
            $this->servicio->actualizarBorrador($recepcion, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['recepcion' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.recepciones.show', $recepcion)
            ->with('status', "Recepción #{$recepcion->numero} actualizada.");
    }

    public function anular(PlantaRecepcion $recepcion): RedirectResponse
    {
        try {
            $this->servicio->anular($recepcion);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['recepcion' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.recepciones.show', $recepcion)
            ->with('status', "Recepción #{$recepcion->numero} anulada.");
    }

    public function confirmar(ConfirmarRecepcionRequest $request, PlantaRecepcion $recepcion): RedirectResponse
    {
        try {
            $this->servicio->confirmar($recepcion, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['recepcion' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.recepciones.show', $recepcion)
            ->with('status', "Recepción #{$recepcion->numero} confirmada: el inventario ya la refleja.");
    }

    public function reversar(ReversarRecepcionRequest $request, PlantaRecepcion $recepcion): RedirectResponse
    {
        try {
            $reversion = $this->servicio->reversar($recepcion, $request->validated()['motivo'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['recepcion' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.recepciones.show', $reversion)
            ->with('status', "Recepción #{$recepcion->numero} reversada con el documento #{$reversion->numero}.");
    }

    /** @return array<string, mixed> */
    private function opcionesFiltro(): array
    {
        return [
            'proveedores' => PlantaProveedor::orderBy('nombre')->get(['id', 'nombre']),
            'ubicaciones' => PlantaUbicacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'insumos' => PlantaInsumo::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Opciones del formulario. Solo se ofrecen ubicaciones que puedan recibir de
     * verdad: activas y con operación manual. El servicio lo vuelve a comprobar.
     *
     * @return array<string, mixed>
     */
    private function opcionesFormulario(): array
    {
        return [
            'proveedores' => PlantaProveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'ubicaciones' => PlantaUbicacion::where('activo', true)
                ->where('permite_operacion_manual', true)
                ->orderBy('orden')->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
            'insumos' => PlantaInsumo::where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'unidad_base', 'controla_lotes', 'permite_fraccion',
                    'unidad_recepcion_sugerida', 'contenido_sugerido', 'factor_conversion_sugerido']),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ];
    }
}
