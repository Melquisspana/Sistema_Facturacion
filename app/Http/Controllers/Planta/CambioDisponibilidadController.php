<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\CambioDisponibilidadRequest;
use App\Http\Requests\Planta\ConfirmarCambioDisponibilidadRequest;
use App\Http\Requests\Planta\ReversarCambioDisponibilidadRequest;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Services\Planta\PlantaCambioDisponibilidadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cambios de disponibilidad: liberar o rechazar saldo retenido.
 *
 * Controlador DELGADO: autoriza, delega y traduce el resultado a una
 * redirección. Confirmar y reversar pasan ENTERAS por
 * {@see PlantaCambioDisponibilidadService}, que abre la transacción, bloquea el
 * documento y habla con el motor de inventario.
 *
 * Las excepciones de dominio se traducen a un mensaje en la redirección: son
 * situaciones esperables —el documento cambió de estado, el saldo ya se movió—
 * y el usuario debe leerlas, no encontrarse un 500.
 */
class CambioDisponibilidadController extends Controller
{
    public function __construct(private readonly PlantaCambioDisponibilidadService $servicio) {}

    public function index(Request $request): View
    {
        $cambios = PlantaCambioDisponibilidad::query()
            ->with(['insumo:id,codigo,nombre,unidad_base', 'lote:id,codigo_interno', 'ubicacion:id,codigo,nombre', 'creadoPor:id,name'])
            ->when($request->filled('numero'), fn ($q) => $q->where('numero', $request->integer('numero')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->date('hasta')))
            ->when($request->filled('insumo'), fn ($q) => $q->where('planta_insumo_id', $request->integer('insumo')))
            ->when($request->filled('ubicacion'), fn ($q) => $q->where('planta_ubicacion_id', $request->integer('ubicacion')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('destino'), fn ($q) => $q->where('estado_destino', $request->string('destino')))
            ->orderByDesc('numero')
            ->paginate(25)
            ->withQueryString();

        return view('planta.disponibilidad.index', [
            'cambios' => $cambios,
            'estados' => EstadoCambioDisponibilidad::cases(),
            'destinos' => [EstadoDisponibilidad::Disponible, EstadoDisponibilidad::Rechazado],
            'insumos' => PlantaInsumo::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'ubicaciones' => PlantaUbicacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function create(): View
    {
        return view('planta.disponibilidad.create', $this->opcionesFormulario());
    }

    public function store(CambioDisponibilidadRequest $request): RedirectResponse
    {
        try {
            $cambio = $this->servicio->crearBorrador($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['cambio' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.disponibilidad.show', $cambio)
            ->with('status', "Cambio de disponibilidad #{$cambio->numero} creado como borrador.");
    }

    public function show(PlantaCambioDisponibilidad $disponibilidad): View
    {
        $disponibilidad->load([
            'insumo:id,codigo,nombre,unidad_base',
            'lote:id,codigo_interno,codigo_proveedor',
            'ubicacion:id,codigo,nombre',
            'creadoPor:id,name',
            'confirmadoPor:id,name',
            'reversionDe:id,numero',
            'revertidoPor:id,numero',
        ]);

        return view('planta.disponibilidad.show', [
            'cambio' => $disponibilidad,
            // Saldo VIVO del bucket de origen: es lo que decide si la confirmación
            // va a poder aplicarse, y el usuario debe verlo antes de intentarlo.
            'saldoRetenido' => $this->servicio->saldoRetenido($disponibilidad),
        ]);
    }

    public function edit(PlantaCambioDisponibilidad $disponibilidad): View|RedirectResponse
    {
        if (! $disponibilidad->esEditable()) {
            return redirect()
                ->route('planta.disponibilidad.show', $disponibilidad)
                ->withErrors(['cambio' => 'Solo se edita un borrador.']);
        }

        return view('planta.disponibilidad.edit', ['cambio' => $disponibilidad] + $this->opcionesFormulario());
    }

    public function update(
        CambioDisponibilidadRequest $request,
        PlantaCambioDisponibilidad $disponibilidad,
    ): RedirectResponse {
        try {
            $this->servicio->actualizarBorrador($disponibilidad, $request->validated());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['cambio' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.disponibilidad.show', $disponibilidad)
            ->with('status', "Cambio #{$disponibilidad->numero} actualizado.");
    }

    public function anular(PlantaCambioDisponibilidad $disponibilidad): RedirectResponse
    {
        try {
            $this->servicio->anular($disponibilidad);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cambio' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.disponibilidad.show', $disponibilidad)
            ->with('status', "Cambio #{$disponibilidad->numero} anulado.");
    }

    public function confirmar(
        ConfirmarCambioDisponibilidadRequest $request,
        PlantaCambioDisponibilidad $disponibilidad,
    ): RedirectResponse {
        try {
            $this->servicio->confirmar($disponibilidad, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cambio' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.disponibilidad.show', $disponibilidad)
            ->with('status', "Cambio #{$disponibilidad->numero} confirmado: el inventario ya lo refleja.");
    }

    public function reversar(
        ReversarCambioDisponibilidadRequest $request,
        PlantaCambioDisponibilidad $disponibilidad,
    ): RedirectResponse {
        try {
            $reversion = $this->servicio->reversar(
                $disponibilidad,
                $request->validated()['motivo'],
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cambio' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.disponibilidad.show', $reversion)
            ->with('status', "Cambio #{$disponibilidad->numero} reversado con el documento #{$reversion->numero}.");
    }

    /**
     * Opciones del formulario. Los buckets ofrecidos son SOLO los que tienen
     * saldo retenido: dejar capturar la combinación a mano permitiría pedir un
     * cambio sobre saldo que no existe.
     *
     * @return array<string, mixed>
     */
    private function opcionesFormulario(): array
    {
        return [
            'buckets' => $this->servicio->bucketsRetenidosConSaldo(),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
            'destinos' => [EstadoDisponibilidad::Disponible, EstadoDisponibilidad::Rechazado],
        ];
    }
}
