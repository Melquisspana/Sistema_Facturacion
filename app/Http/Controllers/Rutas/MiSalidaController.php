<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\PedidosSala;
use App\Services\Rutas\Ritmo;
use App\Services\Rutas\RitmoRutas;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MiSalidaController extends Controller
{
    /** Hasta esta cantidad, «Tu próxima salida» lista los CCF uno por uno. */
    private const CCF_EN_LISTA = 10;

    public function __construct(private readonly EntregasCcf $entregas, private readonly RitmoRutas $ritmo, private readonly PedidosSala $pedidos) {}

    private function persona(Request $request): ?PersonalRuta
    {
        return PersonalRuta::where('user_id', $request->user()->id)->where('activo', true)->first();
    }

    /**
     * Quién entregó: el vendedor puede marcar a un compañero de la salida; si no elige,
     * es él. Que la persona vaya en la salida lo valida {@see EntregasCcf::registrar()}.
     */
    private function quienEntrega(Request $request): int
    {
        return $request->filled('entregado_por_id') ? (int) $request->input('entregado_por_id') : $this->persona($request)->id;
    }

    /** Las salidas planificadas donde va la persona, la más próxima primero. */
    private function proximas(PersonalRuta $persona): Builder
    {
        return SalidaRuta::with('ruta')->enEstado(EstadoSalidaRuta::Planificada)
            ->whereHas('personal', fn ($q) => $q->where('rutas_personal.id', $persona->id))
            ->orderBy('fecha_inicio')->orderBy('id');
    }

    public function index(Request $request): View|RedirectResponse
    {
        $persona = $this->persona($request);
        $salidas = $persona ? SalidaRuta::with('ruta')->enEstado(EstadoSalidaRuta::EnCurso)
            ->whereHas('personal', fn ($q) => $q->where('rutas_personal.id', $persona->id))
            ->orderBy('fecha_inicio')->orderBy('id')->get() : collect();
        $proximas = $persona ? $this->proximas($persona)->get() : collect();

        // La salida en curso manda; si no hay, se abre la próxima («Tu próxima salida»).
        if ($salidas->count() === 1) {
            return redirect()->route('rutas.mi-salida.show', $salidas->first());
        }
        if ($salidas->isEmpty() && $proximas->isNotEmpty()) {
            return redirect()->route('rutas.mi-salida.show', $proximas->first());
        }

        return view('rutas.mi-salida.index', compact('persona', 'salidas', 'proximas'));
    }

    public function show(Request $request, SalidaRuta $salida): View
    {
        if ($salida->estado === EstadoSalidaRuta::Planificada) {
            return $this->proxima($request, $salida);
        }

        Gate::authorize('view', $salida);
        $persona = $this->persona($request);
        $salida->load(['ruta', 'participantes.personal']);
        // Lo facturado con la salida en camino entra solo; se avisa junto al mensaje que ya hubiera.
        if (($nuevos = $this->entregas->cargarNuevosEnCurso($salida)) > 0) {
            session()->now('status', trim(session('status').' '.EntregasCcf::avisoNuevos($nuevos)));
        }
        // Los facturados después de entregar (registros viejos) solo se ven en la hoja de la oficina.
        $entregas = $salida->entregas()->sinFacturadosDespues()->with(['dte.cliente:id,cobro_en_entrega', 'sala', 'entregadoPor'])->get();
        $resoluciones = $this->entregas->resolucionesAlbaran($entregas);

        return view('rutas.mi-salida.show', [
            'salida' => $salida,
            'persona' => $persona,
            // En curso manda; la próxima queda como enlace al pie.
            'proxima' => $this->proximas($persona)->first(),
            'porSala' => $entregas->sortBy(fn ($e) => [$e->sala?->nombre ?? '', $e->dte?->numero_control ?? ''])->groupBy('cliente_sucursal_id'),
            'participantes' => $salida->participantes->filter(fn ($p) => $p->personal?->activo),
            'resoluciones' => $resoluciones,
            'noVigentes' => $this->entregas->noVigentes($entregas),
            'resumen' => $this->entregas->resumen($entregas, $resoluciones),
            'motivos' => MotivoNoEntrega::opciones(),
            'modoVendedor' => true,
            'puedeGestionar' => false,
            'registrable' => ! $salida->diaCerrado(),
            'abierta' => true,
            'salasPedido' => $salida->proposito->sacaPedido() ? $this->salasParaPedido($salida) : collect(),
            'pedidosPorSala' => $salida->proposito->sacaPedido()
                ? $salida->pedidos()->registrados()->with('lineas.producto:id,nombre')->get()->keyBy('cliente_sucursal_id')
                : collect(),
        ]);
    }

    /**
     * Las salas donde se saca pedido en esta salida, con el ritmo del tablero: las atrasadas
     * primero. Al final, las de otra ruta que llevan un CCF en esta salida.
     *
     * @return Collection<int, array{sala: ClienteSucursal, estado: ?string}>
     */
    private function salasParaPedido(SalidaRuta $salida): Collection
    {
        $deRuta = $this->ritmo->salasDeRuta($salida->ruta, CarbonImmutable::parse(HoraNegocio::fechaHoy()))
            ->map(fn (array $f) => ['sala' => $f['sala'], 'estado' => $f['estado']]);
        $otras = $this->pedidos->salasDeSalida($salida)->whereNotIn('id', $deRuta->pluck('sala.id'))
            ->orderBy('nombre')->get(['id', 'ruta_id', 'nombre', 'codigo'])
            ->map(fn (ClienteSucursal $sala) => ['sala' => $sala, 'estado' => null]);

        return $deRuta->concat($otras)->values();
    }

    /**
     * «Tu próxima salida»: la planificada, solo para mirar. Ruta, día, quiénes van, las
     * salas que tocan sacar pedido ese día (las atrasadas primero, con el ritmo del
     * tablero) y los CCF que lleva. No se registra nada hasta «Empezar ruta».
     */
    private function proxima(Request $request, SalidaRuta $salida): View
    {
        Gate::authorize('verProxima', $salida);
        $persona = $this->persona($request);
        $salida->load(['ruta', 'participantes.personal']);
        $hoy = HoraNegocio::fechaHoy();
        $fecha = $salida->fecha_inicio->toDateString();

        // El ritmo al día de la salida: lo que toca cuando llegue, no lo de hoy.
        $salas = $this->ritmo->salasDeRuta($salida->ruta, CarbonImmutable::parse(max($fecha, $hoy)));
        [$tocan, $otras] = $salas->partition(fn (array $s) => in_array($s['estado'], [Ritmo::ATRASADA, Ritmo::PRONTO], true));

        $entregas = $salida->entregas()->sinFacturadosDespues()
            ->with(['dte:id,numero_control,total_pagar,estado', 'sala:id,nombre'])->get()
            ->sortBy(fn ($e) => [$e->sala?->nombre ?? '', $e->dte?->numero_control ?? ''])->values();

        return view('rutas.mi-salida.proxima', [
            'salida' => $salida,
            'companeros' => $salida->quienesVan($persona->id),
            'tocan' => $tocan->values(),
            'otrasSalas' => $otras->count(),
            'entregas' => $entregas,
            'totalCcf' => (float) $entregas->sum(fn ($e) => (float) $e->dte?->total_pagar),
            'listarCcf' => $entregas->count() <= self::CCF_EN_LISTA,
            'noVigentes' => $this->entregas->noVigentes($entregas),
            'puedeEmpezar' => $fecha <= $hoy,
            'despues' => $this->proximas($persona)->whereKeyNot($salida->id)->get(),
        ]);
    }

    /**
     * «Empezar ruta»: la planificada pasa a en curso, solo el día de la salida o después, y
     * se cargan los CCF de la ruta facturados desde que se armó ({@see EntregasCcf::cargarPendientes()}).
     * Con la salida bloqueada, para que dos toques (o el compañero a la vez) no la
     * empiecen dos veces: si ya está en curso, no se hace nada.
     */
    public function empezar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        [$tipo, $mensaje] = DB::transaction(function () use ($request, $salida) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            Gate::authorize('empezar', $salida);

            if ($salida->estado === EstadoSalidaRuta::EnCurso) {
                return ['status', 'La ruta ya estaba en curso.'];
            }
            if ($salida->estado !== EstadoSalidaRuta::Planificada) {
                return ['error', 'Esta salida está '.mb_strtolower($salida->estado->label()).': ya no se puede empezar.'];
            }
            if ($salida->fecha_inicio->toDateString() > HoraNegocio::fechaHoy()) {
                return ['error', 'Esta salida es para el '.$salida->fecha_inicio->copy()->locale('es')->dayName.' '
                    .$salida->fecha_inicio->format('d/m/Y').'. La podés empezar ese día.'];
            }

            $salida->iniciar();
            activity('salida_ruta')
                ->performedOn($salida)
                ->causedBy($request->user())
                ->withProperties([
                    'estado_anterior' => EstadoSalidaRuta::Planificada->value,
                    'estado_nuevo' => EstadoSalidaRuta::EnCurso->value,
                    'desde' => 'mi_salida',
                ])
                ->log('empezó la ruta');

            // Lo facturado para la ruta desde que se armó la salida también se lleva, como al
            // «Salir a esta ruta». En la misma transacción: si falla, la ruta no queda empezada.
            $nuevos = $this->entregas->cargarPendientes($salida, $request->user());

            return ['status', trim('Ruta empezada. '.EntregasCcf::avisoNuevos($nuevos)).' Ya podés marcar las entregas.'];
        });

        return redirect()->route('rutas.mi-salida.show', $salida)->with($tipo, $mensaje);
    }

    public function update(Request $request, SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        Gate::authorize('registrar', $salida);
        $registrada = $this->entregas->registrar($entrega, $request->only(['resultado', 'forma_cobro', 'trae_nota_averia', 'motivo_no_entrega', 'nota'])
            + ['entregado_por_id' => $this->quienEntrega($request)], $request->user(), OrigenRegistroEntrega::Vendedor);

        return back()->with('status', 'Entrega registrada: '.$registrada->resultado->label().'.');
    }

    public function deshacer(SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        DB::transaction(function () use ($salida, $entrega) {
            // La oficina puede corregir a la vez: autorizar sobre el registro bloqueado.
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $entrega = $salida->entregas()->lockForUpdate()->findOrFail($entrega->id);
            Gate::authorize('deshacer', [$salida, $entrega]);
            $this->entregas->deshacer($entrega);
        });

        return back()->with('status', 'El CCF volvió a quedar sin registrar.');
    }

    public function sala(Request $request, SalidaRuta $salida, ClienteSucursal $sucursal): RedirectResponse
    {
        Gate::authorize('registrar', $salida);
        abort_unless($salida->entregas()->where('cliente_sucursal_id', $sucursal->id)->exists(), 404);
        $marcados = $this->entregas->registrarSala($salida, $sucursal->id, $this->quienEntrega($request), $request->user(), OrigenRegistroEntrega::Vendedor);

        return back()->with('status', "«{$sucursal->nombre}»: {$marcados} CCF marcados como entregados.");
    }
}
