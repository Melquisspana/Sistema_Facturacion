<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Http\Controllers\Controller;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Todos los viajes»: los viajes y las salidas de antes y su detalle. Desde el 10/11/2026
 * nadie arma salidas: el viaje se abre y se cierra solo ({@see Viajes}).
 *
 * La única acción de estado que queda acá es cancelar, con su ruta, su permiso y su registro
 * de auditoría: para el viaje que se abrió por error. El modelo valida la transición.
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

        // Sin los facturados después de entregar (registros de antes del 04/11/2026, sin dinero):
        // no cuentan en ninguna pantalla, como en la lista y en «Por entregar».
        $entregas = $salida->entregas()->sinFacturadosDespues()
            ->with(['dte:id,numero_control,fecha_emision,total_pagar,numero_orden_compra,cliente_id,cliente_sucursal_id,estado', 'dte.cliente:id,cobro_en_entrega', 'sala:id,nombre,codigo', 'entregadoPor:id,nombre', 'registradoPor:id,name'])
            ->get();

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
            // El viaje no se liquida: su control es el PDF de cada día de cada vendedor.
            'diasPdf' => $salida->esViaje() ? $dia->diasDelViaje($salida) : [],
        ]);
    }

    // ------------------------------------------------------------- transiciones

    /**
     * Cancela un viaje (o una salida de antes) que sigue abierto, y lo audita. Si el modelo
     * rechaza la transición (ya está cerrado o cancelado) no se escribe nada y el usuario
     * recibe un error en vez de un cambio silencioso.
     */
    public function cancelar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $anterior = $salida->estado;

        if (! $salida->cancelar()) {
            return back()->with('error', "No se puede cancelar una salida {$anterior->label()}.");
        }

        $this->auditar($request, $salida, 'canceló la salida', [
            'estado_anterior' => $anterior->value,
            'estado_nuevo' => $salida->estado->value,
        ]);

        return back()->with('status', 'Salida cancelada.');
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
