<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\PermisoSistema;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\SalidaRutaParticipante;
use App\Services\Dte\DtePdfService;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\PorEntregar;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Por entregar» (Melqui, 06/11/2026): los CCF que faltan entregar, por la ruta de su sala,
 * para que el vendedor los marque sin que nadie arme la salida. Qué está por entregar lo
 * decide {@see EntregasCcf::porEntregar()}; en qué salida queda, {@see PorEntregar}; y el
 * registro, {@see EntregasCcf::registrar()}, el mismo de «Mi salida». Deshacer usa la ruta
 * de «Mi salida».
 *
 * Mirar: el vendedor (`rutas.entregas.registrar`) y la oficina (`rutas.ver`). Marcar: solo el
 * vendedor con su persona de Rutas activa.
 */
class PorEntregarController extends Controller
{
    private const SIN_RUTA = 'sin';

    public function __construct(private readonly EntregasCcf $entregas, private readonly PorEntregar $porEntregar) {}

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

        $dtes = $this->entregas->porEntregar()->whereNotIn('id', $marcados->pluck('dte_id'))
            ->load(['clienteSucursal:id,nombre,ruta_id', 'clienteSucursal.ruta:id,nombre', 'cliente:id,nombre,nombre_comercial,cobro_en_entrega']);
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

        $grupos = $dtes->groupBy(fn (Dte $d) => $d->clienteSucursal?->ruta_id ?? self::SIN_RUTA)
            ->map(fn ($lista, $clave) => [
                'clave' => (string) $clave,
                'nombre' => $clave === self::SIN_RUTA ? 'Sin ruta' : $lista->first()->clienteSucursal->ruta->nombre,
                'dtes' => $lista,
            ])
            ->sortBy(fn (array $g) => [$g['clave'] === self::SIN_RUTA ? 1 : 0, mb_strtolower($g['nombre'])])
            ->values();

        // El filtro de ruta es solo para mirar (?ruta=): no guarda nada.
        $ruta = (string) $request->query('ruta', '');
        $ruta = $grupos->contains(fn (array $g) => $g['clave'] === $ruta) ? $ruta : null;

        return view('rutas.por-entregar.index', [
            'grupos' => $grupos,
            'visibles' => $ruta === null ? $grupos : $grupos->where('clave', $ruta)->values(),
            'ruta' => $ruta,
            'filtroInvalido' => $request->filled('ruta') && $ruta === null,
            'total' => $dtes->count(),
            'enCamino' => $enCamino,
            'trabados' => $trabados,
            // Su automática de hoy ya cobró: el efectivo se rinde cuando la oficina la termina.
            'cobroHoy' => $puedeMarcar && SalidaRuta::enEstado(EstadoSalidaRuta::EnCurso)->automaticas()
                ->whereDate('fecha_inicio', HoraNegocio::fechaHoy())
                ->whereHas('participantes', fn ($q) => $q->where('rutas_personal_id', $persona->id))
                ->whereHas('entregas', fn ($q) => $q->entregadas()->where('forma_cobro', FormaCobroEntrega::Efectivo->value))->exists(),
            'marcados' => $marcados,
            'persona' => $persona,
            'puedeMarcar' => $puedeMarcar,
            'motivos' => MotivoNoEntrega::opciones(),
            'sinRuta' => self::SIN_RUTA,
        ]);
    }

    /**
     * Marca el CCF. Todo en una transacción: si el registro no pasa (falta el motivo, el día
     * está cerrado), no queda una salida armada ni el vendedor sumado a ella.
     */
    public function marcar(Request $request, Dte $dte): RedirectResponse
    {
        $persona = $this->persona($request);
        if ($persona === null) {
            return back()->with('error', 'Tu usuario no está enlazado a una persona de Rutas. Pedile a la oficina que lo enlace.');
        }
        abort_if($this->entregas->porEntregar($dte->id)->isEmpty(), 404);

        $registrada = DB::transaction(function () use ($request, $dte, $persona) {
            $entrega = $this->porEntregar->entregaPara($dte, $persona, $request->user());
            Gate::authorize('registrar', $entrega->salida);

            return $this->entregas->registrar($entrega, $request->only(['resultado', 'forma_cobro', 'trae_nota_averia', 'motivo_no_entrega', 'nota'])
                + ['entregado_por_id' => $persona->id], $request->user(), OrigenRegistroEntrega::Vendedor);
        });

        return back()->with('status', 'Entrega registrada: '.$registrada->resultado->label().' (salida '.$registrada->salida->descripcionCorta().').');
    }

    /** El PDF, solo de un CCF de la lista: cualquier otro documento da 404. */
    public function pdf(Dte $dte, DtePdfService $pdf): Response
    {
        abort_if($this->entregas->porEntregar($dte->id)->isEmpty(), 404);

        return $pdf->pdf($dte)->download($pdf->nombre($dte));
    }
}
