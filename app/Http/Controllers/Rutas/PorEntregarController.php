<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\PermisoSistema;
use App\Enums\ResultadoEntrega;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRutaEntrega;
use App\Models\SalidaRutaParticipante;
use App\Services\Dte\DtePdfService;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\FacturarPedido;
use App\Services\Rutas\PorEntregar;
use App\Services\Rutas\Viajes;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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

    public function __construct(private readonly EntregasCcf $entregas, private readonly PorEntregar $porEntregar, private readonly Viajes $viajes) {}

    private function persona(Request $request): ?PersonalRuta
    {
        return PersonalRuta::where('user_id', $request->user()->id)->where('activo', true)->first();
    }

    public function index(Request $request): View
    {
        $persona = $this->persona($request);
        $puedeMarcar = $persona !== null && $request->user()->can(PermisoSistema::RutasEntregasRegistrar->value);

        // Lo que el vendedor marcó hoy queda abajo, con «Deshacer», y sale de la lista.
        $marcados = $puedeMarcar ? SalidaRutaEntrega::with(['dte:id,numero_control,total_pagar', 'sala:id,nombre', 'salida'])
            ->where('registrado_por', $request->user()->id)->whereNotNull('resultado')->sinFacturadosDespues()
            ->whereDate('fecha_resultado', HoraNegocio::fechaHoy())
            ->whereHas('salida', fn ($q) => $q->enEstado(EstadoSalidaRuta::EnCurso))
            ->orderByDesc('fecha_resultado')->get() : collect();

        $todos = $this->entregas->porEntregar()->whereNotIn('id', $marcados->pluck('dte_id'))
            ->load(['clienteSucursal:id,nombre,ruta_id,distrito_id,departamento_id', 'cliente:id,nombre,nombre_comercial,cobro_en_entrega']);
        // El pedido de cada CCF: quién lo sacó («lo sacó otro vendedor») y lo que no vino.
        $pedidos = PedidoSalaEnvio::vigentes()->whereIn('dte_id', $todos->modelKeys())
            ->with(['pedido.sacadoPor:id,nombre', 'pedido.lineas.producto:id,nombre', 'pedido.lineas.envioLineasVigentes'])
            ->orderBy('id')->get()->mapWithKeys(fn (PedidoSalaEnvio $e) => [$e->dte_id => $e->pedido]);

        // Con su viaje en curso: «Este viaje» y «Toda la zona»; si no, todo, como antes.
        $viaje = $persona ? $this->viajes->abierto($persona) : null;
        $vistas = $viaje ? $this->porEntregar->vistas($viaje, $todos) : [];
        $ver = array_key_exists((string) $request->query('ver'), $vistas) || $request->query('ver') === 'todas'
            ? (string) $request->query('ver') : (array_key_first($vistas) ?? 'todas');
        $dtes = $ver === 'todas' ? $todos : $vistas[$ver];
        // Los que ya van en una salida abierta: se marcan en ella (o esperan a que empiece).
        $enCamino = SalidaRutaEntrega::with('salida.ruta:id,nombre')->whereIn('dte_id', $dtes->pluck('id'))->pendientes()
            ->whereHas('salida', fn ($q) => $q->abiertas())->get()->keyBy('dte_id');
        // Dónde no se puede marcar (sin botones, con el porqué): una formal donde no va, o
        // una salida que ya cerró el día de hoy. Las mismas reglas de PorEntregar.
        $vaEn = $puedeMarcar ? SalidaRutaParticipante::where('rutas_personal_id', $persona->id)
            ->whereIn('salida_ruta_id', $enCamino->pluck('salida_ruta_id'))->pluck('salida_ruta_id')->map(fn ($id) => (int) $id)->all() : [];
        $trabados = ! $puedeMarcar ? collect() : $enCamino->map(fn (SalidaRutaEntrega $e) => match (true) {
            $e->salida->estado !== EstadoSalidaRuta::EnCurso => null,
            $e->salida->diaCerrado() => PorEntregar::diaCerrado($e->salida),
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
     * se ignora.
     */
    public function marcar(Request $request, Dte $dte): RedirectResponse
    {
        $persona = $this->persona($request);
        if ($persona === null) {
            return back()->with('error', 'Tu usuario no está enlazado a una persona de Rutas. Pedile a la oficina que lo enlace.');
        }
        abort_if($this->entregas->porEntregar($dte->id)->isEmpty(), 404);

        $portal = DiaVendedor::esPortal($dte);
        $request->validate([
            'forma_cobro' => [
                Rule::requiredIf(fn () => $request->input('resultado') === ResultadoEntrega::Entregado->value && ! $portal),
                'nullable', Rule::in([...array_column(FormaCobroEntrega::cases(), 'value'), EntregasCcf::SIN_COBRO]),
            ],
        ], ['forma_cobro.*' => 'Elegí cómo te pagaron: efectivo, cheque, transferencia o quedan.']);
        $datos = $request->only(['resultado', 'forma_cobro', 'trae_nota_averia', 'motivo_no_entrega', 'nota', 'quedan_numero', 'quedan_fecha_pago']);
        if ($portal) {
            $datos['forma_cobro'] = EntregasCcf::SIN_COBRO;
        }

        $registrada = DB::transaction(function () use ($request, $dte, $persona, $datos) {
            $entrega = $this->porEntregar->entregaPara($dte, $persona, $request->user());
            Gate::authorize('registrar', $entrega->salida);

            return $this->entregas->registrar($entrega, $datos + ['entregado_por_id' => $persona->id], $request->user(), OrigenRegistroEntrega::Vendedor);
        });

        return back()->with('status', 'Entrega registrada: '.$registrada->resultado->label().' (salida '.$registrada->salida->descripcionCorta().').');
    }

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
