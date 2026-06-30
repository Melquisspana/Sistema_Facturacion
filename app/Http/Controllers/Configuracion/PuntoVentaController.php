<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\PuntoVentaRequest;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PuntoVentaController extends Controller
{
    public function index(): View
    {
        $puntosVenta = PuntoVenta::with('establecimiento')->orderBy('codigo')->get();

        return view('configuracion.puntos-venta.index', compact('puntosVenta'));
    }

    public function create(): View
    {
        return view('configuracion.puntos-venta.form', [
            'puntoVenta' => new PuntoVenta(['activo' => true]),
            'establecimientos' => Establecimiento::orderBy('codigo')->get(),
        ]);
    }

    public function store(PuntoVentaRequest $request): RedirectResponse
    {
        PuntoVenta::create($request->validated());

        return redirect()
            ->route('configuracion.puntos-venta.index')
            ->with('status', 'Punto de venta creado correctamente.');
    }

    public function edit(PuntoVenta $puntoVenta): View
    {
        return view('configuracion.puntos-venta.form', [
            'puntoVenta' => $puntoVenta,
            'establecimientos' => Establecimiento::orderBy('codigo')->get(),
        ]);
    }

    public function update(PuntoVentaRequest $request, PuntoVenta $puntoVenta): RedirectResponse
    {
        $puntoVenta->update($request->validated());

        return redirect()
            ->route('configuracion.puntos-venta.index')
            ->with('status', 'Punto de venta actualizado correctamente.');
    }

    public function destroy(PuntoVenta $puntoVenta): RedirectResponse
    {
        if ($puntoVenta->correlativos()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene correlativos asociados. Desactívelo en su lugar.');
        }

        $puntoVenta->delete();

        return redirect()
            ->route('configuracion.puntos-venta.index')
            ->with('status', 'Punto de venta eliminado.');
    }
}
