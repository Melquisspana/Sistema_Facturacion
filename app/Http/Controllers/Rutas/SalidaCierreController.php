<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\SalidaRuta;
use App\Services\Rutas\CierreDia;
use App\Support\HoraNegocio;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SalidaCierreController extends Controller
{
    public function __construct(private readonly CierreDia $cierres) {}

    private function autorizar(Request $request, SalidaRuta $salida, string $accion = 'view'): void
    {
        if ($request->routeIs('rutas.mi-salida.*')) {
            Gate::authorize($accion, $salida);
        } else {
            abort_unless($request->user()->can('rutas.gestionar'), 403);
        }
    }

    private function fechaValida(string $fecha): void
    {
        abort_if(Validator::make(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d']])->fails(), 404);
    }

    private function fecha(Request $request): string
    {
        if ($request->routeIs('rutas.mi-salida.*')) {
            return HoraNegocio::fechaHoy();
        }

        return $request->validate(['fecha' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:'.HoraNegocio::fechaHoy()]])['fecha'] ?? HoraNegocio::fechaHoy();
    }

    public function cierre(Request $request, SalidaRuta $salida): View
    {
        $this->autorizar($request, $salida);
        $fecha = $this->fecha($request);
        $entregas = $this->cierres->entregas($salida, $fecha);
        $cierre = $salida->cierreDelDia($fecha);
        $modoVendedor = $request->routeIs('rutas.mi-salida.*');
        $prefijo = $modoVendedor ? 'rutas.mi-salida.' : 'rutas.salidas.';
        $participantes = $salida->personal()->where('activo', true)->get();
        $pendientes = $salida->entregas()->pendientes()->count();

        return view('rutas.cierres.show', compact('salida', 'fecha', 'entregas', 'cierre', 'modoVendedor', 'prefijo', 'participantes', 'pendientes'));
    }

    public function cerrar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $this->autorizar($request, $salida);
        $fecha = $this->fecha($request);
        DB::transaction(function () use ($request, $salida, $fecha) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $this->autorizar($request, $salida, 'registrar');
            $this->cierres->cerrar($salida, $fecha, $request->only(['entregas', 'entrega_dinero_personal_id']), $request->user());
        });
        $prefijo = $request->routeIs('rutas.mi-salida.*') ? 'rutas.mi-salida.' : 'rutas.salidas.';

        return redirect()->route($prefijo.'cierre', ['salida' => $salida, 'fecha' => $fecha])->with('status', 'Día cerrado. La liquidación está lista.');
    }

    public function reabrir(Request $request, SalidaRuta $salida, string $fecha): RedirectResponse
    {
        abort_unless($request->user()->can('rutas.gestionar'), 403);
        $this->fechaValida($fecha);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']]);
        $this->cierres->reabrir($salida, $fecha, $datos['motivo'], $request->user());

        return redirect()->route('rutas.salidas.cierre', ['salida' => $salida, 'fecha' => $fecha])->with('status', 'Día reabierto. Corregí las entregas y volvé a cerrarlo.');
    }

    public function liquidacion(Request $request, SalidaRuta $salida, string $fecha): Response
    {
        $this->autorizar($request, $salida, 'liquidacion');
        $this->fechaValida($fecha);
        $cierre = $salida->cierres()->whereDate('fecha', $fecha)->firstOrFail();
        $salida->load(['ruta', 'personal']);
        $cierre->load('entregaDinero');
        $entregas = $this->cierres->entregas($salida, $fecha);
        $empresa = $entregas->first()?->dte?->establecimiento?->empresa
            ?? $salida->entregas()->with('dte.establecimiento.empresa')->first()?->dte?->establecimiento?->empresa;

        return Pdf::loadView('rutas.cierres.pdf', compact('salida', 'fecha', 'cierre', 'entregas', 'empresa'))
            ->setPaper('a4', 'portrait')->setOption('enable_font_subsetting', true)
            ->download('liquidacion-'.Str::slug($salida->ruta->nombre).'-'.$fecha.'.pdf');
    }
}
