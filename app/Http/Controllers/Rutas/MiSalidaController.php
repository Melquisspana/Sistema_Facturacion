<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\FichaVendedor;
use App\Services\Rutas\PorEntregar;
use App\Services\Rutas\Viajes;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * El celular del vendedor (flujo único del 10/11/2026): su inicio, «Terminar el día» con el
 * PDF y, para las salidas armadas a mano de antes que sigan en camino, su hoja de siempre.
 * El viaje no tiene hoja: lo suyo está en «Sacar pedido» y «Por entregar».
 */
class MiSalidaController extends Controller
{
    public function __construct(
        private readonly EntregasCcf $entregas,
        private readonly Viajes $viajes,
        private readonly PorEntregar $porEntregar,
        private readonly DiaVendedor $dia,
    ) {}

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

    /**
     * El inicio del vendedor (maqueta del 10/11/2026): su viaje, «Sacar pedido» y «Por
     * entregar», lo de hoy y «Terminar el día». Debajo, las salidas armadas a mano de antes
     * que sigan en camino, hasta que se terminen.
     */
    public function index(Request $request): View
    {
        app(FichaVendedor::class)->asegurar($request->user());
        $persona = $this->persona($request);
        $viaje = $persona ? $this->viajes->abierto($persona) : null;
        $salidas = $persona ? SalidaRuta::with('ruta')->enEstado(EstadoSalidaRuta::EnCurso)
            ->where('automatica', false)->where(fn ($q) => $q->whereNull('observaciones')->orWhere('observaciones', '!=', SalidaRuta::OBSERVACION_AUTOMATICA))
            ->whereHas('personal', fn ($q) => $q->where('rutas_personal.id', $persona->id))
            ->orderBy('fecha_inicio')->orderBy('id')->get() : collect();

        return view('rutas.mi-salida.index', [
            'persona' => $persona,
            'viaje' => $viaje,
            'dia' => $viaje ? $this->viajes->dia($viaje) : null,
            'sumando' => $viaje !== null && $this->viajes->sigueSumando($viaje),
            'salasConPedido' => $viaje ? $viaje->pedidos()->vigentes()->distinct()->count('cliente_sucursal_id') : 0,
            'hoy' => $persona ? $this->dia->resumen($persona, $request->user(), HoraNegocio::fechaHoy()) : null,
            'porEntregar' => $persona ? $this->cuantosPorEntregar($viaje) : 0,
            'salidas' => $salidas,
        ]);
    }

    /**
     * Lo que le queda en «Por entregar»: lo de su viaje (o de su zona, si el viaje todavía no
     * tiene CCF); sin viaje, todo. El mismo criterio de la lista.
     */
    private function cuantosPorEntregar(?SalidaRuta $viaje): int
    {
        $todos = $this->entregas->porEntregar();
        if ($viaje === null) {
            return $todos->count();
        }
        $vistas = $this->porEntregar->vistas($viaje, $todos->load('clienteSucursal:id,ruta_id,distrito_id,departamento_id'));

        return reset($vistas)->count();
    }

    /**
     * «Terminar el día»: lo que entregó, lo que no se pudo y lo cobrado, y la pregunta. Con
     * `?fecha=AAAA-MM-DD`, el resumen de un día anterior (el viaje dura varios días y cada día
     * tiene su PDF firmado). Abajo, un enlace al PDF de cada día anterior con movimiento.
     */
    public function dia(Request $request): View|RedirectResponse
    {
        $persona = $this->persona($request);
        if ($persona === null) {
            return redirect()->route('rutas.mi-salida.index');
        }
        $viaje = $this->viajes->abierto($persona);
        $hoy = HoraNegocio::fechaHoy();
        $fecha = $this->fechaDelDia($request, $viaje);
        if ($fecha === null) {
            return $this->diaInvalido();
        }

        return view('rutas.mi-salida.dia', [
            'persona' => $persona,
            'fecha' => $fecha,
            'esHoy' => $fecha === $hoy,
            'resumen' => $this->dia->resumen($persona, $request->user(), $fecha),
            'viaje' => $viaje,
            'sumando' => $viaje !== null && $this->viajes->sigueSumando($viaje),
            // Los gastos del día se anotan en el viaje y salen en el PDF. Solo los de hoy.
            'puedeAnotarGastos' => $fecha === $hoy && $viaje !== null && $request->user()->can('agregarGasto', [$viaje, $hoy]),
            'diasAnteriores' => array_values(array_filter(
                $this->dia->diasConMovimiento($persona, $request->user(), $viaje ? $viaje->fecha_inicio->toDateString() : $this->desdeSinViaje(), $hoy),
                fn (string $d) => $d !== $hoy,
            )),
        ]);
    }

    /** El PDF del día (hoy, o `?fecha=`): lo entregado, lo que no se pudo y lo cobrado, con el nombre de la empresa. */
    public function diaPdf(Request $request): Response|RedirectResponse
    {
        $persona = $this->persona($request);
        if ($persona === null) {
            return redirect()->route('rutas.mi-salida.index');
        }
        $fecha = $this->fechaDelDia($request, $this->viajes->abierto($persona));
        if ($fecha === null) {
            return $this->diaInvalido();
        }

        return $this->dia->pdf($persona, $request->user(), $fecha);
    }

    /**
     * El día pedido (`?fecha=AAAA-MM-DD`) o, sin él, hoy. Vale si no es futuro y está dentro
     * de su viaje abierto o es de hasta {@see DiaVendedor::DIAS_ATRAS} días atrás. Null si no vale.
     */
    private function fechaDelDia(Request $request, ?SalidaRuta $viaje): ?string
    {
        $hoy = HoraNegocio::fechaHoy();
        if (! $request->has('fecha')) {
            return $hoy;
        }
        $desde = min($this->desdeSinViaje(), $viaje?->fecha_inicio->toDateString() ?? $hoy);
        $fecha = $request->query('fecha');

        return is_string($fecha) && Validator::make(['fecha' => $fecha], [
            'fecha' => ['date_format:Y-m-d', 'after_or_equal:'.$desde, 'before_or_equal:'.$hoy],
        ])->passes() ? $fecha : null;
    }

    private function desdeSinViaje(): string
    {
        return Carbon::parse(HoraNegocio::fechaHoy())->subDays(DiaVendedor::DIAS_ATRAS)->toDateString();
    }

    private function diaInvalido(): RedirectResponse
    {
        return redirect()->route('rutas.mi-salida.dia')
            ->with('error', 'Ese día no se puede abrir: solo los días de tu viaje o de los últimos '.DiaVendedor::DIAS_ATRAS.' días.');
    }

    /** «Terminar el día»: si mañana no sigue en la misma zona, el viaje deja de sumar pedidos. */
    public function terminarDia(Request $request, SalidaRuta $salida): RedirectResponse
    {
        Gate::authorize('terminarDia', $salida);
        $sigue = $request->validate(['sigue' => ['required', 'boolean']], [
            'sigue.required' => 'Contestá si mañana seguís en la misma zona.',
            'sigue.boolean' => 'Contestá si mañana seguís en la misma zona.',
        ])['sigue'];
        $this->viajes->terminarDia($salida, (bool) $sigue, $request->user());

        return redirect()->route('rutas.mi-salida.index')->with('status', $sigue
            ? 'Listo. Mañana tus pedidos se siguen sumando a este viaje.'
            : 'Listo. Este viaje ya no suma pedidos; queda abierto para entregar.');
    }

    /**
     * La hoja de una salida armada a mano de antes, mientras siga en camino. Lo demás (el
     * viaje, una salida que quedó programada o una ya cerrada) no tiene hoja: va al inicio,
     * para quien tenga el enlace guardado.
     */
    public function show(Request $request, SalidaRuta $salida): View|RedirectResponse
    {
        if ($salida->esAutomatica() || $salida->estado !== EstadoSalidaRuta::EnCurso) {
            return redirect()->route('rutas.mi-salida.index');
        }

        Gate::authorize('view', $salida);
        $persona = $this->persona($request);
        $salida->load(['ruta', 'participantes.personal']);
        // Los facturados después de entregar (registros viejos) solo se ven en la hoja de la oficina.
        $entregas = $salida->entregas()->sinFacturadosDespues()->with(['dte.cliente:id,cobro_en_entrega', 'sala', 'entregadoPor'])->get();
        $resoluciones = $this->entregas->resolucionesAlbaran($entregas);

        return view('rutas.mi-salida.show', [
            'salida' => $salida,
            'persona' => $persona,
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
            // Los pedidos de esta salida (los del viaje); sacar uno nuevo es desde «Sacar pedido».
            'pedidosPorSala' => $salida->proposito->sacaPedido()
                ? $salida->pedidos()->vigentes()->withExists('envios')->with(['sala:id,nombre', 'lineas.producto:id,nombre'])->orderBy('id')->get()
                    ->each(fn (PedidoSala $p) => $p->setRelation('salida', $salida))
                : collect(),
        ]);
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

        // En la automática la fila se borra, y si era la única la salida se cancela: volver
        // atrás daría 403, así que se va a «Por entregar», donde el CCF aparece otra vez.
        $salida->refresh();
        if ($salida->esAutomatica()) {
            $aviso = 'Se deshizo: el CCF vuelve a Por entregar.';

            return $salida->estado === EstadoSalidaRuta::EnCurso ? back()->with('status', $aviso)
                : redirect()->route('rutas.por-entregar.index')->with('status', $aviso);
        }

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
