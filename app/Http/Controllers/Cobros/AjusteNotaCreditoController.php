<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Cobros\CobroAjuste;
use App\Services\Cobros\NotaCreditoAjuste;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AjusteNotaCreditoController extends Controller
{
    public function index(CobroAjuste $ajuste, NotaCreditoAjuste $servicio): View
    {
        $ajuste->load('notaCredito');
        $servicio->validarPendiente($ajuste);

        return view('cobros.vincular-nc', [
            'ajuste' => $ajuste,
            'notas' => $servicio->candidatas($ajuste)->with('clienteSucursal')->paginate(20),
        ]);
    }

    public function store(Request $request, CobroAjuste $ajuste, NotaCreditoAjuste $servicio): RedirectResponse
    {
        $datos = $request->validate(['nc_dte_id' => ['required', 'integer', 'exists:dtes,id']]);
        $servicio->vincular($ajuste, $datos['nc_dte_id']);

        return redirect()->route('cobros.index', ['cliente_id' => $ajuste->cliente_id])->with('status', 'Nota de crédito vinculada al ajuste.');
    }

    public function destroy(CobroAjuste $ajuste, NotaCreditoAjuste $servicio): RedirectResponse
    {
        $servicio->desvincular($ajuste);

        return redirect()->route('cobros.index', ['cliente_id' => $ajuste->cliente_id])->with('status', 'Nota de crédito desvinculada del ajuste.');
    }
}
