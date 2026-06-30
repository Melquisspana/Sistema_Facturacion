<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\AmbienteHacienda;
use App\Enums\TipoDte;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\CorrelativoRequest;
use App\Models\Correlativo;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CorrelativoController extends Controller
{
    public function index(): View
    {
        $correlativos = Correlativo::with(['establecimiento', 'puntoVenta'])
            ->orderBy('establecimiento_id')
            ->orderBy('tipo_dte')
            ->get();

        return view('configuracion.correlativos.index', compact('correlativos'));
    }

    public function create(): View
    {
        return view('configuracion.correlativos.form', $this->datosFormulario(new Correlativo(['ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true])));
    }

    public function store(CorrelativoRequest $request): RedirectResponse
    {
        Correlativo::create($request->datosCorrelativo());

        return redirect()
            ->route('configuracion.correlativos.index')
            ->with('status', 'Correlativo creado correctamente.');
    }

    public function edit(Correlativo $correlativo): View
    {
        return view('configuracion.correlativos.form', $this->datosFormulario($correlativo));
    }

    public function update(CorrelativoRequest $request, Correlativo $correlativo): RedirectResponse
    {
        $correlativo->update($request->datosCorrelativo());

        return redirect()
            ->route('configuracion.correlativos.index')
            ->with('status', 'Correlativo actualizado correctamente.');
    }

    public function destroy(Correlativo $correlativo): RedirectResponse
    {
        $correlativo->delete();

        return redirect()
            ->route('configuracion.correlativos.index')
            ->with('status', 'Correlativo eliminado.');
    }

    private function datosFormulario(Correlativo $correlativo): array
    {
        return [
            'correlativo' => $correlativo,
            'tiposDte' => TipoDte::habilitados(),
            'ambientes' => AmbienteHacienda::cases(),
            'establecimientos' => Establecimiento::orderBy('codigo')->get(),
            'puntosVenta' => PuntoVenta::with('establecimiento')->orderBy('codigo')->get(),
        ];
    }
}
