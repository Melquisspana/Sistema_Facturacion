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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MiSalidaController extends Controller
{
    public function __construct(private readonly EntregasCcf $entregas) {}

    private function persona(Request $request): ?PersonalRuta
    {
        return PersonalRuta::where('user_id', $request->user()->id)->where('activo', true)->first();
    }

    public function index(Request $request): View|RedirectResponse
    {
        $persona = $this->persona($request);
        $salidas = $persona ? SalidaRuta::with('ruta')->enEstado(EstadoSalidaRuta::EnCurso)
            ->whereHas('personal', fn ($q) => $q->where('rutas_personal.id', $persona->id))
            ->orderBy('fecha_inicio')->orderBy('id')->get() : collect();

        if ($salidas->count() === 1) {
            return redirect()->route('rutas.mi-salida.show', $salidas->first());
        }

        return view('rutas.mi-salida.index', compact('persona', 'salidas'));
    }

    public function show(Request $request, SalidaRuta $salida): View
    {
        Gate::authorize('view', $salida);
        $salida->load(['ruta', 'participantes.personal']);
        $entregas = $salida->entregas()->with(['dte', 'sala', 'entregadoPor'])->get();
        $resoluciones = $this->entregas->resolucionesAlbaran($entregas);

        return view('rutas.mi-salida.show', [
            'salida' => $salida,
            'persona' => $this->persona($request),
            'porSala' => $entregas->sortBy(fn ($e) => [$e->sala?->nombre ?? '', $e->dte?->numero_control ?? ''])->groupBy('cliente_sucursal_id'),
            'participantes' => $salida->participantes->filter(fn ($p) => $p->personal?->activo),
            'resoluciones' => $resoluciones,
            'noVigentes' => $this->entregas->noVigentes($entregas),
            'resumen' => $this->entregas->resumen($entregas, $resoluciones),
            'motivos' => MotivoNoEntrega::opciones(),
            'modoVendedor' => true,
            'puedeGestionar' => false,
            'registrable' => true,
            'abierta' => true,
        ]);
    }

    public function update(Request $request, SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        Gate::authorize('registrar', $salida);
        // Quién entregó lo pone el servidor: siempre la persona de este usuario.
        $registrada = $this->entregas->registrar($entrega, $request->only(['resultado', 'trae_nota_averia', 'motivo_no_entrega', 'nota'])
            + ['entregado_por_id' => $this->persona($request)->id], $request->user(), OrigenRegistroEntrega::Vendedor);

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
        $marcados = $this->entregas->registrarSala($salida, $sucursal->id, $this->persona($request)->id, $request->user(), OrigenRegistroEntrega::Vendedor);

        return back()->with('status', "«{$sucursal->nombre}»: {$marcados} CCF marcados como entregados.");
    }
}
