<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\AjusteRequest;
use App\Http\Requests\Planta\ConfirmarAjusteRequest;
use App\Http\Requests\Planta\ReversarAjusteRequest;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Services\Planta\PlantaAjusteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Ajustes de inventario.
 *
 * Controlador DELGADO: autoriza, delega y traduce el resultado a una
 * redirección. Confirmar y reversar pasan ENTERAS por
 * {@see PlantaAjusteService}, que abre la transacción, bloquea el documento y
 * habla con el motor de inventario.
 *
 * Las excepciones de dominio se traducen a un mensaje en la redirección: son
 * situaciones esperables —falta saldo, el bucket ya tiene historial, el lote no
 * ha vencido— y el usuario debe leerlas, no encontrarse un 500.
 */
class AjusteController extends Controller
{
    public function __construct(private readonly PlantaAjusteService $servicio) {}

    public function index(Request $request): View
    {
        $ajustes = PlantaAjuste::query()
            ->with(['creadoPor:id,name', 'confirmadoPor:id,name'])
            ->withCount('detalles')
            ->when($request->filled('numero'), fn ($q) => $q->where('numero', $request->integer('numero')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->date('hasta')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('creado_por'), fn ($q) => $q->where('creado_por', $request->integer('creado_por')))
            ->when($request->filled('insumo'), fn ($q) => $q->whereHas('detalles', fn ($d) => $d->where('planta_insumo_id', $request->integer('insumo'))))
            ->when($request->filled('lote'), fn ($q) => $q->whereHas('detalles', fn ($d) => $d->where('planta_lote_id', $request->integer('lote'))))
            ->when($request->filled('ubicacion'), fn ($q) => $q->whereHas('detalles', fn ($d) => $d->where('planta_ubicacion_id', $request->integer('ubicacion'))))
            ->when($request->filled('disponibilidad'), fn ($q) => $q->whereHas('detalles', fn ($d) => $d->where('estado_disponibilidad', $request->string('disponibilidad'))))
            ->orderByDesc('numero')
            ->paginate(25)
            ->withQueryString();

        return view('planta.ajustes.index', [
            'ajustes' => $ajustes,
            'tipos' => TipoAjuste::cases(),
            'estados' => EstadoAjustePlanta::cases(),
            'disponibilidades' => EstadoDisponibilidad::cases(),
            'insumos' => PlantaInsumo::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'lotes' => PlantaLote::reales()->orderBy('codigo_interno')->get(['id', 'codigo_interno']),
            'ubicaciones' => PlantaUbicacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function create(): View
    {
        return view('planta.ajustes.create', $this->opcionesFormulario());
    }

    public function store(AjusteRequest $request): RedirectResponse
    {
        try {
            $ajuste = $this->servicio->crearBorrador($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['ajuste' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.ajustes.show', $ajuste)
            ->with('status', "Ajuste #{$ajuste->numero} creado como borrador.");
    }

    public function show(PlantaAjuste $ajuste): View
    {
        $ajuste->load([
            'detalles.insumo:id,codigo,nombre,unidad_base',
            'detalles.lote:id,codigo_interno,fecha_vencimiento',
            'detalles.ubicacion:id,codigo,nombre',
            'creadoPor:id,name',
            'confirmadoPor:id,name',
            'reversionDe:id,numero',
            'revertidoPor:id,numero',
        ]);

        return view('planta.ajustes.show', [
            'ajuste' => $ajuste,
            // Saldo VIVO de cada bucket: es lo que decide si la confirmación va a
            // poder aplicarse, y debe verse antes de intentarlo.
            'saldos' => $this->saldosDe($ajuste),
        ]);
    }

    public function edit(PlantaAjuste $ajuste): View|RedirectResponse
    {
        if (! $ajuste->esEditable()) {
            return redirect()
                ->route('planta.ajustes.show', $ajuste)
                ->withErrors(['ajuste' => 'Solo se edita un borrador.']);
        }

        $ajuste->load('detalles');

        return view('planta.ajustes.edit', ['ajuste' => $ajuste] + $this->opcionesFormulario());
    }

    public function update(AjusteRequest $request, PlantaAjuste $ajuste): RedirectResponse
    {
        try {
            $this->servicio->actualizarBorrador($ajuste, $request->validated());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['ajuste' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.ajustes.show', $ajuste)
            ->with('status', "Ajuste #{$ajuste->numero} actualizado.");
    }

    public function anular(PlantaAjuste $ajuste): RedirectResponse
    {
        try {
            $this->servicio->anular($ajuste);
        } catch (RuntimeException $e) {
            return back()->withErrors(['ajuste' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.ajustes.show', $ajuste)
            ->with('status', "Ajuste #{$ajuste->numero} anulado.");
    }

    public function confirmar(ConfirmarAjusteRequest $request, PlantaAjuste $ajuste): RedirectResponse
    {
        try {
            $this->servicio->confirmar($ajuste, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['ajuste' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.ajustes.show', $ajuste)
            ->with('status', "Ajuste #{$ajuste->numero} confirmado: el inventario ya lo refleja.");
    }

    public function reversar(ReversarAjusteRequest $request, PlantaAjuste $ajuste): RedirectResponse
    {
        try {
            $reversion = $this->servicio->reversar($ajuste, $request->validated()['motivo'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['ajuste' => $e->getMessage()]);
        }

        return redirect()
            ->route('planta.ajustes.show', $reversion)
            ->with('status', "Ajuste #{$ajuste->numero} reversado con el documento #{$reversion->numero}.");
    }

    /**
     * Saldo actual de cada bucket del documento, indexado por id de línea.
     *
     * @return array<int, string>
     */
    private function saldosDe(PlantaAjuste $ajuste): array
    {
        $saldos = [];

        foreach ($ajuste->detalles as $detalle) {
            $valor = DB::table('planta_existencias')->where($detalle->bucket()->aColumnas())->value('cantidad');
            $saldos[$detalle->id] = bcadd((string) ($valor ?? '0'), '0', 4);
        }

        return $saldos;
    }

    /**
     * Opciones del formulario.
     *
     * `buckets` son los saldos que YA existen, para los tipos que restan: ofrecer
     * combinaciones sin saldo llevaría a descubrir el error al confirmar en vez
     * de al escribir. Las ubicaciones excluyen TRÁNSITO por construcción —solo se
     * ofrecen las que admiten operación manual—.
     *
     * @return array<string, mixed>
     */
    private function opcionesFormulario(): array
    {
        $buckets = DB::table('planta_existencias as e')
            ->join('planta_insumos as i', 'i.id', '=', 'e.planta_insumo_id')
            ->join('planta_lotes as l', 'l.id', '=', 'e.planta_lote_id')
            ->join('planta_ubicaciones as u', 'u.id', '=', 'e.planta_ubicacion_id')
            ->where('e.planta_traslado_id', 0)
            ->where('e.cantidad', '>', 0)
            ->where('u.permite_operacion_manual', true)
            ->where('u.activo', true)
            ->orderBy('i.nombre')->orderBy('l.codigo_interno')
            ->get([
                'e.planta_insumo_id', 'e.planta_lote_id', 'e.planta_ubicacion_id', 'e.estado', 'e.cantidad',
                'i.codigo as insumo_codigo', 'i.nombre as insumo_nombre', 'i.unidad_base',
                'l.codigo_interno as lote_codigo', 'u.codigo as ubicacion_codigo',
            ]);

        return [
            'buckets' => $buckets,
            'tipos' => TipoAjuste::cases(),
            'disponibilidades' => EstadoDisponibilidad::cases(),
            'ubicaciones' => PlantaUbicacion::where('activo', true)
                ->where('permite_operacion_manual', true)
                ->orderBy('orden')->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
            'insumos' => PlantaInsumo::where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'unidad_base', 'controla_lotes']),
            'lotesReales' => PlantaLote::reales()->where('activo', true)
                ->orderBy('codigo_interno')
                ->get(['id', 'planta_insumo_id', 'codigo_interno', 'fecha_vencimiento']),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ];
    }
}
