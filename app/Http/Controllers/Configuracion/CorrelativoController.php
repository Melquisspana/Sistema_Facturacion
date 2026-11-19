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
        // Borrado definitivo: los documentos quedarían sin correlativo y uno nuevo desde
        // cero repetiría sus números al emitir.
        if ($correlativo->dtes()->exists()) {
            return back()->with('error', 'Este correlativo ya tiene facturas emitidas. Desactivalo en su lugar.');
        }
        // Sin documentos, un número mayor que cero es lo que se numeró fuera del sistema (por
        // ejemplo, alineado a Conta Portable): solo queda acá, y Hacienda ya tiene esos números.
        if ($correlativo->ultimo_numero > 0) {
            return back()->with('error', "Este correlativo ya va en el número {$correlativo->ultimo_numero}: esos números ya se usaron, aunque no estén en el sistema. Desactivalo en su lugar.");
        }

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
