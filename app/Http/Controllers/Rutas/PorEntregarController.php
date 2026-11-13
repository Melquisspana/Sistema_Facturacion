<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\PermisoSistema;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaCierre;
use App\Models\SalidaRutaEntrega;
use App\Models\SalidaRutaParticipante;
use App\Services\Dte\DtePdfService;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\FacturarPedido;
use App\Services\Rutas\FichaVendedor;
use App\Services\Rutas\PorEntregar;
use App\Services\Rutas\Viajes;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Por entregar» (Melqui, 06/11/2026): los CCF que faltan entregar, por la zona de su sala,
 * para que el vendedor los marque sin que nadie arme la salida. Qué está por entregar lo
 * decide {@see EntregasCcf::porEntregar()}; en qué salida queda, {@see PorEntregar}; y el
 * registro, {@see EntregasCcf::registrar()}, el mismo de «Mi salida». Deshacer usa la ruta
 * de «Mi salida».
 *
 * Mirar: el vendedor (`rutas.entregas.registrar`) y la oficina (`rutas.ver`). Marcar: solo el
 * vendedor con su persona de Rutas activa.
 *
 * Desde el 10/11/2026 cualquier vendedor entrega cualquier CCF: con su viaje en curso, arriba
 * elige «Este viaje» (lo que salió de sus pedidos) o «Toda la zona» (lo de las zonas del
 * viaje, haya salido de un pedido o no); «Todas» es la lista completa por zona. Al entregar
 * dice cómo le pagaron, salvo en el supermercado que cobra por el portal. La zona de una sala
 * es su ruta o la que cubre su lugar ({@see Viajes::zonaId()}); sin zona se entrega igual.
 */
class PorEntregarController extends Controller
{
    private const SIN_RUTA = 'sin';

    /** Lo que necesita el nombre del viaje ({@see SalidaRuta::nombreViaje()}), cargado una vez para todos. */
    private const NOMBRE_DEL_VIAJE = ['salida.ruta:id,nombre', 'salida.pedidos:id,salida_ruta_id,ruta_id,estado', 'salida.pedidos.ruta:id,nombre'];

    public function __construct(private readonly EntregasCcf $entregas, private readonly PorEntregar $porEntregar, private readonly Viajes $viajes) {}

    private function persona(Request $request): ?PersonalRuta
    {
        return PersonalRuta::where('user_id', $request->user()->id)->where('activo', true)->first();
    }

    public function index(Request $request): View
    {
        app(FichaVendedor::class)->asegurar($request->user());
        $persona = $this->persona($request);
        $puedeMarcar = $persona !== null && $request->user()->can(PermisoSistema::RutasEntregasRegistrar->value);

        // Lo que el vendedor marcó hoy queda abajo, con «Deshacer», y sale de la lista.
        $marcados = $puedeMarcar ? SalidaRutaEntrega::with(['dte:id,numero_control,numero_orden_compra,total_pagar,cliente_id', 'sala:id,nombre', 'salida', 'entregadoPor:id,nombre', ...self::NOMBRE_DEL_VIAJE])
            ->where('registrado_por', $request->user()->id)->whereNotNull('resultado')->sinFacturadosDespues()
            ->whereDate('fecha_resultado', HoraNegocio::fechaHoy())
            ->whereHas('salida', fn ($q) => $q->enEstado(EstadoSalidaRuta::EnCurso))
            ->orderByDesc('fecha_resultado')->get() : collect();

        $todos = $this->entregas->porEntregar()->whereNotIn('id', $marcados->pluck('dte_id'))
            ->load(['clienteSucursal:id,nombre,ruta_id,distrito_id,departamento_id', 'cliente:id,nombre,nombre_comercial,cobro_en_entrega']);
        // El pedido de cada CCF: quién lo sacó («lo sacó otro vendedor») y lo que no vino.
        $pedidos = PedidoSalaEnvio::vigentes()->whereIn('dte_id', $todos->modelKeys())
            ->with(['pedido.sacadoPor:id,nombre', 'pedido.lineas.producto:id,nombre,unidad_medida_id', 'pedido.lineas.producto.unidadMedida:id,abreviatura', 'pedido.lineas.envioLineasVigentes'])
            ->orderBy('id')->get()->mapWithKeys(fn (PedidoSalaEnvio $e) => [$e->dte_id => $e->pedido]);

        // Con su viaje en curso: «Este viaje» y «Toda la zona»; si no, todo, como antes.
        $viaje = $persona ? $this->viajes->abierto($persona) : null;
        $vistas = $viaje ? $this->porEntregar->vistas($viaje, $todos) : [];
        $ver = array_key_exists((string) $request->query('ver'), $vistas) || $request->query('ver') === 'todas'
            ? (string) $request->query('ver') : (array_key_first($vistas) ?? 'todas');
        $dtes = $ver === 'todas' ? $todos : $vistas[$ver];
        // Los que ya van en una salida abierta: se marcan en ella (o esperan a que empiece).
        $enCamino = SalidaRutaEntrega::with(self::NOMBRE_DEL_VIAJE)->whereIn('dte_id', $dtes->pluck('id'))->pendientes()
            ->whereHas('salida', fn ($q) => $q->abiertas())->get()->keyBy('dte_id');
        // Dónde no se puede marcar (sin botones, con el porqué): una formal donde no va, o
        // una salida que ya cerró el día de hoy. Las mismas reglas de PorEntregar.
        $vaEn = $puedeMarcar ? SalidaRutaParticipante::where('rutas_personal_id', $persona->id)
            ->whereIn('salida_ruta_id', $enCamino->pluck('salida_ruta_id'))->pluck('salida_ruta_id')->map(fn ($id) => (int) $id)->all() : [];
        // El cierre de hoy de todas esas salidas, en una consulta: la misma regla de
        // SalidaRuta::diaCerrado() (un cierre por salida y día).
        $cierresDeHoy = ! $puedeMarcar ? collect() : SalidaRutaCierre::whereIn('salida_ruta_id', $enCamino->pluck('salida_ruta_id')->unique())
            ->whereDate('fecha', HoraNegocio::fechaHoy())->get()->keyBy('salida_ruta_id');
        $trabados = ! $puedeMarcar ? collect() : $enCamino->map(fn (SalidaRutaEntrega $e) => match (true) {
            $e->salida->estado !== EstadoSalidaRuta::EnCurso => null,
            $cierresDeHoy->get($e->salida_ruta_id)?->estaCerrado() ?? false => PorEntregar::diaCerrado($e->salida),
            ! $e->salida->esAutomatica() && ! in_array((int) $e->salida_ruta_id, $vaEn, true) => PorEntregar::noVa($e->salida),
            default => null,
        })->filter();

        // Por zona: la ruta de la sala o la que cubre su lugar. Los nombres, en una consulta.
        $zonaDe = fn (Dte $d) => $d->clienteSucursal ? $this->viajes->zonaId($d->clienteSucursal) : null;
        $zonas = Ruta::whereKey($dtes->map($zonaDe)->filter()->unique()->all())->pluck('nombre', 'id');
        $grupos = $dtes->groupBy(fn (Dte $d) => $zonaDe($d) ?? self::SIN_RUTA)
            ->map(fn ($lista, $clave) => [
                'clave' => (string) $clave,
                'nombre' => $clave === self::SIN_RUTA ? 'Sin zona' : $zonas[$clave],
                'dtes' => $lista,
            ])
            ->sortBy(fn (array $g) => [$g['clave'] === self::SIN_RUTA ? 1 : 0, mb_strtolower($g['nombre'])])
            ->values();

        // El filtro de ruta es solo para mirar (?ruta=): no guarda nada.
        $ruta = (string) $request->query('ruta', '');
        $ruta = $grupos->contains(fn (array $g) => $g['clave'] === $ruta) ? $ruta : null;

        return view('rutas.por-entregar.index', [
            'vistas' => array_map(fn ($lista) => $lista->count(), $vistas) + ($vistas ? ['todas' => $todos->count()] : []),
            'ver' => $ver,
            'pedidos' => $pedidos,
            // La factura que no se pudo entregar dos veces o más: aviso para el jefe.
            'fallas' => $this->entregas->fallas($dtes->modelKeys()),
            'portal' => DiaVendedor::clientePortal(),
            'grupos' => $grupos,
            'visibles' => $ruta === null ? $grupos : $grupos->where('clave', $ruta)->values(),
            'ruta' => $ruta,
            'filtroInvalido' => $request->filled('ruta') && $ruta === null,
            'total' => $dtes->count(),
            'enCamino' => $enCamino,
            'trabados' => $trabados,
            // Ya cobró efectivo hoy: se entrega a la oficina con el PDF del día.
            'cobroHoy' => $marcados->contains(fn (SalidaRutaEntrega $e) => $e->forma_cobro === FormaCobroEntrega::Efectivo),
            'marcados' => $marcados,
            // La sala con albarán anotada: si su albarán ya cayó o se está esperando.
            'albaranMarcados' => $this->entregas->resolucionesAlbaran($marcados->filter(fn (SalidaRutaEntrega $e) => $e->dte !== null && DiaVendedor::esPortal($e->dte))),
            // Su albarán cayó sin que nadie anotara quién la entregó: se anota acá.
            'sinQuien' => $puedeMarcar ? $this->entregas->conAlbaranSinQuien()->load(['clienteSucursal:id,nombre', 'cliente:id,nombre,nombre_comercial']) : collect(),
            // «¿Quién lo entregó?»: los vendedores activos, sin nadie elegido de antemano.
            'quienes' => $puedeMarcar ? $this->porEntregar->quienesEntregan($request->user()) : collect(),
            'persona' => $persona,
            'puedeMarcar' => $puedeMarcar,
            'motivos' => MotivoNoEntrega::opciones(),
        ]);
    }

    /**
     * Marca el CCF. Todo en una transacción: si el registro no pasa (falta el motivo, el día
     * está cerrado), no queda una salida armada ni el vendedor sumado a ella.
     *
     * «Entregado» pide cómo pagaron (efectivo, cheque, transferencia o quedan), salvo en el
     * supermercado que cobra por el portal: ahí no se cobra al entregar y la forma que llegue
     * se ignora. Esa regla es de {@see EntregasCcf::registrar()}: vale por cualquier pantalla.
     *
     * «¿Quién lo entregó?» (12/11/2026) va en toda entrega, también en «No se pudo»: cualquier
     * vendedor anota a otro, y la entrega (y su dinero) va en el PDF del día del que la entregó.
     * La entrega queda en la salida del que anota (así la puede deshacer), con el que entregó
     * sumado. La sala con albarán solo anota quién: queda «Entregado» sin cobro esperando el
     * albarán, como la ve Cobros. La que ya tiene albarán sin quién ({@see EntregasCcf::conAlbaranSinQuien()})
     * solo anota quién. El doble toque no anota otra: avisa que ya estaba.
     */
    public function marcar(Request $request, Dte $dte): RedirectResponse
    {
        $persona = $this->persona($request);
        if ($persona === null) {
            return back()->with('error', 'Tu usuario no es vendedor: podés mirar la lista, pero no marcar entregas.');
        }
        // La sala con albarán cuyo albarán ya cayó sin que nadie anotara quién: solo falta eso.
        $soloQuien = $this->entregas->porEntregar($dte->id)->isEmpty();
        if ($soloQuien && $this->entregas->conAlbaranSinQuien($dte->id)->isEmpty()) {
            // El doble toque: la entrega ya quedó anotada y no se anota otra.
            abort_unless(SalidaRutaEntrega::where('dte_id', $dte->id)->entregadas()->exists(), 404);

            return back()->with('status', 'Esa entrega ya estaba anotada.');
        }
        $quien = $this->quienEntrego($request);

        // La sala con albarán no cobra al entregar: lo impone EntregasCcf::registrar().
        $datos = $soloQuien ? ['resultado' => 'entregado']
            : $request->only(['resultado', 'forma_cobro', 'trae_nota_averia', 'motivo_no_entrega', 'nota', 'quedan_numero', 'quedan_fecha_pago']);

        $registrada = DB::transaction(function () use ($request, $dte, $persona, $quien, $datos) {
            $entrega = $this->porEntregar->entregaPara($dte, $persona, $request->user());
            Gate::authorize('registrar', $entrega->salida);
            if ($quien->id !== $persona->id) {
                $this->porEntregar->sumarQuienEntrego($entrega->salida, $quien, $request->user());
            }

            return $this->entregas->registrar($entrega, $datos + ['entregado_por_id' => $quien->id], $request->user(), OrigenRegistroEntrega::Vendedor);
        });

        // Solo el viaje se nombra: la salida que se arma sola para entregar no le dice nada al vendedor.
        $salida = $registrada->salida;

        return back()->with('status', 'Entrega registrada: '.$registrada->resultado->label().($salida->esViaje() ? ' (viaje '.$salida->nombreViaje().')' : '').'.');
    }

    /**
     * «¿Quién lo entregó?»: uno de la lista de la tarjeta ({@see PorEntregar::quienesEntregan()}),
     * validado acá y no en el formulario. Es obligatorio (12/11/2026): antes, sin elegir, quedaba
     * el que tiene el celular y el que prestaba el suyo figuraba como que entregó todo.
     *
     * @throws ValidationException
     */
    private function quienEntrego(Request $request): PersonalRuta
    {
        $quienes = $this->porEntregar->quienesEntregan($request->user());
        $id = $request->validate(
            ['entregado_por_id' => ['required', 'integer', Rule::in($quienes->modelKeys())]],
            ['entregado_por_id.required' => 'Tocá quién lo entregó.', 'entregado_por_id.integer' => self::QUIEN_INVALIDO, 'entregado_por_id.in' => self::QUIEN_INVALIDO],
        )['entregado_por_id'];

        return $quienes->firstWhere('id', (int) $id);
    }

    private const QUIEN_INVALIDO = 'Elegí quién lo entregó entre los vendedores activos.';

    /**
     * La hoja del pedido de un CCF de la lista (el sugerido en limpio, con la F en lo que
     * faltó), para que el vendedor la imprima y la firme el gerente. Sin pedido, 404.
     */
    public function hoja(Dte $dte, FacturarPedido $facturar): View
    {
        abort_if($this->entregas->porEntregar($dte->id)->isEmpty(), 404);
        $pedido = PedidoSalaEnvio::vigentes()->where('dte_id', $dte->id)->latest('id')->first()?->pedido ?? abort(404);

        return view('rutas.pedidos.hoja', PedidoSalaController::datosHoja($pedido, $facturar, null) + ['volverPorEntregar' => true]);
    }

    /** El PDF, solo de un CCF de la lista: cualquier otro documento da 404. */
    public function pdf(Dte $dte, DtePdfService $pdf): Response
    {
        abort_if($this->entregas->porEntregar($dte->id)->isEmpty(), 404);

        return $pdf->pdf($dte)->download($pdf->nombre($dte));
    }
}
