<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Http\Controllers\Controller;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «Viajes anteriores»: los viajes y las salidas de antes, su hoja y las tres acciones de
 * estado. Desde el 10/11/2026 nadie arma salidas: el viaje se abre solo ({@see Viajes}).
 *
 * Los cambios de estado NO son ediciones de un campo: son actos con nombre propio
 * (iniciar, finalizar, cancelar), cada uno con su ruta, su permiso y su registro
 * de auditoría. El modelo valida la transición; acá se traduce a mensaje. Quedan para
 * cerrar las salidas viejas.
 */
class SalidaRutaController extends Controller
{
    public function index(Request $request, EntregasCcf $servicio): View
    {
        $salidas = SalidaRuta::query()
            // Los pedidos con su zona: el viaje se nombra con ellos sin consultar fila por fila.
            ->with(['ruta:id,nombre', 'personal:id,nombre', 'entregas.dte:id,numero_orden_compra',
                'pedidos:id,salida_ruta_id,ruta_id,estado', 'pedidos.ruta:id,nombre'])
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

    public function show(SalidaRuta $salida, EntregasCcf $servicio, DiaVendedor $dia): View
    {
        $salida->load(['ruta', 'creador:id,name', 'participantes.personal:id,nombre,activo']);

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
            // El viaje no se liquida: su control es el PDF de cada día de cada vendedor.
            'diasPdf' => $salida->esViaje() ? $dia->diasDelViaje($salida) : [],
        ]);
    }

    // ------------------------------------------------------------- transiciones

    /**
     * «Salir ahora» de una salida vieja que quedó programada, con la salida bloqueada. Ya no
     * carga los pendientes de la ruta: lo que falta entregar se marca desde «Por entregar».
     */
    public function iniciar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        [$tipo, $mensaje] = DB::transaction(function () use ($request, $salida) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $anterior = $salida->estado;

            // La programada para otro día sale ese día, no antes.
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

            return ['status', 'Salida iniciada.'];
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
