<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\MotivoNoEntrega;
use App\Http\Controllers\Controller;
use App\Models\SalidaRuta;
use App\Services\Rutas\LiquidacionSalida;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * «Terminar ruta»: la oficina o el vendedor que va en la salida (decisión del usuario,
 * 03/11/2026). El vendedor solo en las suyas y mientras está en curso; después puede ver y
 * descargar la liquidación. La regla vive en {@see LiquidacionSalida}.
 */
class SalidaLiquidacionController extends Controller
{
    public function __construct(private readonly LiquidacionSalida $liquidacion) {}

    private function vendedor(Request $request): bool
    {
        return $request->routeIs('rutas.mi-salida.*');
    }

    public function show(Request $request, SalidaRuta $salida): View
    {
        if ($this->vendedor($request)) {
            Gate::authorize('liquidacion', $salida);
        }
        $salida->load(['ruta', 'personal', 'entregaDinero', 'liquidadaPor']);

        return view('rutas.liquidacion.show', [
            'salida' => $salida,
            'totales' => $this->liquidacion->totales($salida),
            'diasSinTerminar' => $this->liquidacion->diasSinTerminar($salida),
            'sinMarcar' => $salida->estaLiquidada() ? collect() : $this->liquidacion->sinMarcar($salida),
            'noEntregadas' => $this->liquidacion->noEntregadas($salida),
            'participantes' => $salida->personal()->where('activo', true)->get(),
            'motivos' => MotivoNoEntrega::opciones(),
            'modoVendedor' => $this->vendedor($request),
            'prefijo' => $this->vendedor($request) ? 'rutas.mi-salida.' : 'rutas.salidas.',
        ]);
    }

    public function liquidar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        if ($this->vendedor($request)) {
            Gate::authorize('view', $salida);
        }
        $this->liquidacion->liquidar($salida, $request->only(['motivos', 'entrega_dinero_personal_id']), $request->user());

        return redirect()->route(($this->vendedor($request) ? 'rutas.mi-salida.' : 'rutas.salidas.').'terminar', $salida)->with('status', 'Ruta terminada. La liquidación está lista.');
    }

    public function pdf(Request $request, SalidaRuta $salida): Response
    {
        if ($this->vendedor($request)) {
            Gate::authorize('liquidacion', $salida);
        }
        abort_unless($salida->estaLiquidada(), 404);
        $salida->load(['ruta', 'personal', 'entregaDinero']);
        $empresa = $salida->entregas()->with('dte.establecimiento.empresa')->first()?->dte?->establecimiento?->empresa;

        return Pdf::loadView('rutas.liquidacion.pdf', [
            'salida' => $salida,
            'empresa' => $empresa,
            'totales' => $this->liquidacion->totales($salida),
            'entregadas' => $this->liquidacion->entregadas($salida),
            'noEntregadas' => $this->liquidacion->noEntregadas($salida),
            'gastos' => $salida->gastos()->orderBy('fecha')->orderBy('id')->get(),
        ])->setPaper('a4', 'portrait')->setOption('enable_font_subsetting', true)
            ->download('liquidacion-ruta-'.Str::slug($salida->ruta->nombre).'-'.$salida->fecha_inicio->toDateString().'.pdf');
    }
}
