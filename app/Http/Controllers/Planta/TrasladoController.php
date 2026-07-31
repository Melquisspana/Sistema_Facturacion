<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\AccionTrasladoRequest;
use App\Http\Requests\Planta\ReversarTrasladoRequest;
use App\Http\Requests\Planta\TrasladoRequest;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Services\Planta\PlantaTrasladoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Traslados de inventario entre ubicaciones.
 *
 * Controlador DELGADO: autoriza, delega y traduce el resultado a una
 * redirección. Enviar, recibir y reversar pasan ENTERAS por
 * {@see PlantaTrasladoService}, que abre la transacción, bloquea el documento y
 * habla con el motor de inventario.
 *
 * Las excepciones de dominio se traducen a un mensaje en la redirección: son
 * situaciones esperables —falta saldo, el documento cambió de estado, no existe
 * la ubicación de tránsito— y el usuario debe leerlas, no encontrarse un 500.
 */
class TrasladoController extends Controller
{
    public function __construct(private readonly PlantaTrasladoService $servicio) {}

    public function index(Request $request): View
    {
        $traslados = PlantaTraslado::query()
            ->with(['origen:id,codigo,nombre', 'destino:id,codigo,nombre', 'creadoPor:id,name'])
            ->withCount('detalles')
            ->when($request->filled('numero'), fn ($q) => $q->where('numero', $request->integer('numero')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->date('hasta')))
            ->when($request->filled('origen'), fn ($q) => $q->where('planta_ubicacion_origen_id', $request->integer('origen')))
            ->when($request->filled('destino'), fn ($q) => $q->where('planta_ubicacion_destino_id', $request->integer('destino')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('insumo'), fn ($q) => $q->whereHas(
                'detalles',
                fn ($d) => $d->where('planta_insumo_id', $request->integer('insumo'))
            ))
            ->when($request->filled('lote'), fn ($q) => $q->whereHas(
                'detalles',
                fn ($d) => $d->where('planta_lote_id', $request->integer('lote'))
            ))
            ->orderByDesc('numero')
            ->paginate(25)
            ->withQueryString();

        return view('planta.traslados.index', [
            'traslados' => $traslados,
            'estados' => EstadoTrasladoPlanta::cases(),
            'ubicaciones' => PlantaUbicacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'insumos' => PlantaInsumo::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'lotes' => PlantaLote::reales()->orderBy('codigo_interno')->get(['id', 'codigo_interno']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('planta.traslados.create', $this->opcionesFormulario($request->integer('origen') ?: null));
    }

    public function store(TrasladoRequest $request): RedirectResponse
    {
        try {
            $traslado = $this->servicio->crearBorrador($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} creado como borrador.");
    }

    public function show(PlantaTraslado $traslado): View
    {
        $traslado->load([
            'detalles.insumo:id,codigo,nombre,unidad_base',
            'detalles.lote:id,codigo_interno',
            'origen:id,codigo,nombre',
            'destino:id,codigo,nombre',
            'creadoPor:id,name',
            'enviadoPor:id,name',
            'recibidoPor:id,name',
            'reversionDe:id,numero',
            'revertidoPor:id,numero',
        ]);

        return view('planta.traslados.show', ['traslado' => $traslado]);
    }

    public function edit(PlantaTraslado $traslado): View|RedirectResponse
    {
        if (! $traslado->esEditable()) {
            return redirect()
                ->route('planta.traslados.show', $traslado)
                ->withErrors(['traslado' => 'Solo se edita un borrador.']);
        }

        $traslado->load('detalles');

        return view('planta.traslados.edit', ['traslado' => $traslado]
            + $this->opcionesFormulario($traslado->planta_ubicacion_origen_id));
    }

    public function update(TrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $this->servicio->actualizarBorrador($traslado, $request->validated());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} actualizado.");
    }

    public function cancelar(PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $this->servicio->cancelar($traslado);
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} cancelado.");
    }

    public function enviar(AccionTrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $this->servicio->enviar($traslado, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} enviado: la mercancía está en tránsito.");
    }

    public function recibir(AccionTrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $this->servicio->recibir($traslado, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $traslado)
            ->with('status', "Traslado #{$traslado->numero} recibido: el saldo ya está en el destino.");
    }

    public function reversar(ReversarTrasladoRequest $request, PlantaTraslado $traslado): RedirectResponse
    {
        try {
            $reversion = $this->servicio->reversar($traslado, $request->validated()['motivo'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['traslado' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.traslados.show', $reversion)
            ->with('status', "Traslado #{$traslado->numero} reversado con el documento #{$reversion->numero}.");
    }

    /**
     * Opciones del formulario. Solo se ofrecen ubicaciones que puedan operar de
     * verdad, y los lotes son SOLO los que tienen saldo disponible en el origen
     * elegido: ofrecer combinaciones sin saldo llevaría a descubrir el error al
     * enviar en vez de al escribir.
     *
     * @return array<string, mixed>
     */
    private function opcionesFormulario(?int $origenId): array
    {
        return [
            'ubicaciones' => PlantaUbicacion::where('activo', true)
                ->where('permite_operacion_manual', true)
                ->orderBy('orden')->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
            'origenSeleccionado' => $origenId,
            'disponibles' => $origenId ? $this->servicio->lotesDisponiblesEn($origenId) : collect(),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ];
    }
}
