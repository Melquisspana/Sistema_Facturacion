<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Enums\PropositoSalida;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\SalidaRutaRequest;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\ParticipantesSalida;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Salidas de ruta: alta, edición y las tres acciones de estado.
 *
 * Los cambios de estado NO son ediciones de un campo: son actos con nombre propio
 * (iniciar, finalizar, cancelar), cada uno con su ruta, su permiso y su registro
 * de auditoría. El modelo valida la transición; acá se traduce a mensaje.
 */
class SalidaRutaController extends Controller
{
    public function index(Request $request, EntregasCcf $servicio): View
    {
        $salidas = SalidaRuta::query()
            ->with(['ruta:id,nombre', 'personal:id,nombre', 'entregas.dte:id,numero_orden_compra'])
            ->withCount(['entregas' => fn ($q) => $q->sinFacturadosDespues()])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('ruta_id'), fn ($q) => $q->where('ruta_id', $request->integer('ruta_id')))
            // Lo más reciente primero: el listado se abre para ver qué está pasando
            // ahora, no para leer el historial desde el principio.
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // «CCF entregados» como en el detalle: con albarán de entrega cuenta aunque nadie
        // lo haya marcado todavía.
        $resoluciones = $servicio->resolucionesAlbaran($salidas->getCollection()->flatMap->entregas);
        $salidas->getCollection()->each(fn (SalidaRuta $s) => $s->entregados_count = $servicio->resumen($s->entregas, $resoluciones)['entregados']);

        return view('rutas.salidas.index', [
            'salidas' => $salidas,
            'rutas' => Ruta::orderBy('nombre')->get(['id', 'nombre']),
            'estados' => EstadoSalidaRuta::cases(),
        ]);
    }

    public function create(): View
    {
        return view('rutas.salidas.create', $this->datosFormulario());
    }

    public function store(SalidaRutaRequest $request, ParticipantesSalida $participantes, EntregasCcf $entregas): RedirectResponse
    {
        $datos = $request->validated();
        $fecha = isset($datos['fecha_inicio']) ? Carbon::parse($datos['fecha_inicio'])->toDateString() : HoraNegocio::fechaHoy();
        // Para hoy sale EN CURSO, como «Salir a esta ruta» desde el 27/09/2026. Para un día
        // que viene queda PLANIFICADA hasta ese día (decisión de Melqui, 03/11/2026).
        $planificada = $fecha > HoraNegocio::fechaHoy();

        // La salida y su gente juntas: si algo falla al anotar quiénes van, no queda una
        // salida vacía.
        [$salida, $resultado] = DB::transaction(function () use ($datos, $fecha, $planificada, $request, $participantes) {
            $salida = SalidaRuta::create([
                'ruta_id' => $datos['ruta_id'],
                'fecha_inicio' => $fecha,
                'fecha_fin_estimada' => $datos['fecha_fin_estimada'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'estado' => $planificada ? EstadoSalidaRuta::Planificada : EstadoSalidaRuta::EnCurso,
                'proposito' => $datos['proposito'] ?? PropositoSalida::Entregar->value,
                'created_by' => $request->user()?->id,
            ]);

            return [$salida, $participantes->sincronizar($salida, $datos['personal'], $datos['responsable_id'] ?? null)];
        });

        $this->auditar($request, $salida, 'definió los participantes de la salida', [
            'participantes' => $resultado['agregados'],
            'responsable' => $resultado['responsable'],
        ]);

        // Los CCF pendientes de la ruta se cargan solos: es lo que la salida va a llevar. La
        // que solo saca pedido no lleva ninguno.
        $cargados = $entregas->cargarPendientes($salida->load('ruta'), $request->user());

        return redirect()
            ->route('rutas.salidas.show', $salida)
            ->with('status', ($planificada
                ? 'Salida planificada para el '.$salida->fecha_inicio->format('d/m/Y')
                : 'Salida en camino').($salida->proposito->entrega()
                    ? ' con '.$cargados.' CCF por entregar.'
                    : ' para sacar pedido.'));
    }

    public function show(SalidaRuta $salida, EntregasCcf $servicio): View
    {
        $salida->load(['ruta', 'creador:id,name', 'participantes.personal:id,nombre,activo']);
        // Lo facturado con la salida en camino entra solo, como en «Mi salida».
        if (($nuevos = $servicio->cargarNuevosEnCurso($salida)) > 0) {
            session()->now('status', trim(session('status').' '.EntregasCcf::avisoNuevos($nuevos)));
        }

        // Los facturados después de entregar (registros viejos) van en su bloque aparte.
        [$facturadosDespues, $entregas] = $salida->entregas()
            ->with(['dte:id,numero_control,fecha_emision,total_pagar,numero_orden_compra,cliente_id,cliente_sucursal_id,estado', 'dte.cliente:id,cobro_en_entrega', 'sala:id,nombre,codigo', 'entregadoPor:id,nombre', 'registradoPor:id,name'])
            ->get()
            ->partition(fn ($e) => $e->esFacturadoDespues());

        $resoluciones = $servicio->resolucionesAlbaran($entregas);

        // Quién puede figurar como «entregó»: los que van, activos. Se preselecciona al
        // responsable o, si va una sola persona, a ella.
        $participantes = $salida->participantes
            ->filter(fn ($p) => $p->personal?->activo)
            ->sortBy(fn ($p) => $p->personal->nombre)
            ->values();
        $preseleccion = $salida->participantes->first(fn ($p) => $p->esResponsable())?->rutas_personal_id
            ?? ($participantes->count() === 1 ? $participantes->first()->rutas_personal_id : null);

        return view('rutas.salidas.show', [
            'salida' => $salida,
            'porSala' => $entregas
                ->sortBy(fn ($e) => [$e->sala?->nombre ?? '', $e->dte?->numero_control ?? ''])
                ->groupBy('cliente_sucursal_id'),
            'resoluciones' => $resoluciones,
            'noVigentes' => $servicio->noVigentes($entregas),
            'resumen' => $servicio->resumen($entregas, $resoluciones),
            'participantes' => $participantes,
            'preseleccion' => $preseleccion,
            'motivos' => MotivoNoEntrega::opciones(),
            'abierta' => ! $salida->estado->esTerminal(),
            'registrable' => $salida->estado === EstadoSalidaRuta::EnCurso,
            'facturadosDespues' => $facturadosDespues->sortBy(fn ($e) => [$e->fecha_resultado, $e->sala?->nombre ?? ''])->values(),
        ]);
    }

    public function edit(SalidaRuta $salida): View
    {
        abort_unless($salida->estado->esEditable(), 403, 'Una salida finalizada o cancelada ya no se edita.');

        $salida->load('participantes:id,salida_ruta_id,rutas_personal_id,rol');

        return view('rutas.salidas.edit', $this->datosFormulario() + ['salida' => $salida]);
    }

    public function update(SalidaRutaRequest $request, SalidaRuta $salida, ParticipantesSalida $participantes): RedirectResponse
    {
        abort_unless($salida->estado->esEditable(), 403, 'Una salida finalizada o cancelada ya no se edita.');

        $datos = $request->validated();

        $salida->update([
            'ruta_id' => $datos['ruta_id'],
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin_estimada' => $datos['fecha_fin_estimada'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
            'proposito' => $datos['proposito'] ?? $salida->proposito,
        ]);

        $resultado = $participantes->sincronizar($salida, $datos['personal'], $datos['responsable_id'] ?? null);

        // Solo se audita si de verdad cambió la gente, para que el historial no se llene de
        // «cambió los participantes» vacíos.
        if ($resultado['agregados'] !== [] || $resultado['quitados'] !== []) {
            $this->auditar($request, $salida, 'cambió los participantes de la salida', [
                'agregados' => $resultado['agregados'],
                'quitados' => $resultado['quitados'],
                'responsable' => $resultado['responsable'],
            ]);
        }

        return redirect()
            ->route('rutas.salidas.show', $salida)
            ->with('status', 'Salida actualizada.');
    }

    // ------------------------------------------------------------- transiciones

    /**
     * «Salir ahora» de la oficina: como «Empezar ruta» de {@see MiSalidaController::empezar()},
     * con la salida bloqueada y en la misma transacción carga lo facturado para la ruta desde
     * que se armó. Si falla la carga, la salida no queda iniciada.
     */
    public function iniciar(Request $request, SalidaRuta $salida, EntregasCcf $entregas): RedirectResponse
    {
        [$tipo, $mensaje] = DB::transaction(function () use ($request, $salida, $entregas) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $anterior = $salida->estado;

            // Como «Empezar ruta»: la programada para otro día sale ese día, no antes.
            if ($anterior === EstadoSalidaRuta::Planificada && $salida->fecha_inicio->toDateString() > HoraNegocio::fechaHoy()) {
                return ['error', 'Esta salida es para el '.$salida->fecha_inicio->copy()->locale('es')->dayName.' '
                    .$salida->fecha_inicio->format('d/m/Y').'. La podés empezar ese día.'];
            }

            if (! $salida->iniciar()) {
                return ['error', "No se puede iniciar una salida {$anterior->label()}."];
            }

            $this->auditar($request, $salida, 'inició la salida', [
                'estado_anterior' => $anterior->value,
                'estado_nuevo' => $salida->estado->value,
            ]);

            return ['status', trim('Salida iniciada. '.EntregasCcf::avisoNuevos($entregas->cargarPendientes($salida, $request->user())))];
        });

        return back()->with($tipo, $mensaje);
    }

    public function finalizar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        return $this->cambiarEstado($request, $salida, 'finalizar', 'finalizó la salida', 'Salida finalizada.');
    }

    public function cancelar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        return $this->cambiarEstado($request, $salida, 'cancelar', 'canceló la salida', 'Salida cancelada.');
    }

    /**
     * Aplica una transición y la audita. Si el modelo la rechaza (por ejemplo,
     * finalizar algo que nunca se inició) no se escribe nada y el usuario recibe
     * un error en vez de un cambio silencioso.
     */
    private function cambiarEstado(Request $request, SalidaRuta $salida, string $accion, string $descripcion, string $mensaje): RedirectResponse
    {
        $anterior = $salida->estado;

        if (! $salida->{$accion}()) {
            return back()->with('error', "No se puede {$accion} una salida {$anterior->label()}.");
        }

        $this->auditar($request, $salida, $descripcion, [
            'estado_anterior' => $anterior->value,
            'estado_nuevo' => $salida->estado->value,
        ]);

        return back()->with('status', $mensaje);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Datos comunes de los formularios de alta y edición.
     *
     * Solo rutas ACTIVAS y usuarios ACTIVOS: el formulario no debería ofrecer algo
     * que la validación va a rechazar después.
     *
     * @return array<string, mixed>
     */
    private function datosFormulario(): array
    {
        return [
            'rutas' => Ruta::activas()->orderBy('nombre')->get(['id', 'nombre']),
            // Personal de campo ACTIVO. Sin filtrar por función: nadie tiene ruta fija y
            // cualquiera puede ir a cualquier lado. Las funciones se cargan para poder
            // SUGERIR quién suele quedar a cargo, no para restringir el selector.
            'personal' => PersonalRuta::activos()
                ->with('funciones:id,rutas_personal_id,funcion')
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
        ];
    }

    /** @param  array<string, mixed>  $propiedades */
    private function auditar(Request $request, SalidaRuta $salida, string $descripcion, array $propiedades): void
    {
        activity('salida_ruta')
            ->performedOn($salida)
            ->causedBy($request->user())
            ->withProperties($propiedades)
            ->log($descripcion);
    }
}
