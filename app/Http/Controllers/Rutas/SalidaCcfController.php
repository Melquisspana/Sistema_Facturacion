<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Services\Dte\DtePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * El PDF de un CCF de la salida, para verlo en el celular al tocar la tarjeta. Es la misma
 * representación de Facturación ({@see DtePdfService}); se abre en el navegador y se
 * guarda desde ahí.
 *
 * Solo sirve CCF que van en ESTA salida (la entrega se busca dentro de la salida: otra da
 * 404). En la oficina basta `rutas.ver`, que ya ve la hoja completa; en «Mi salida», solo
 * quien va en la salida. Un CCF invalidado sale con su cinta de «invalidado».
 */
class SalidaCcfController extends Controller
{
    public function show(Request $request, SalidaRuta $salida, SalidaRutaEntrega $entrega, DtePdfService $pdf): Response
    {
        if ($request->routeIs('rutas.mi-salida.*')) {
            Gate::authorize('verCcf', $salida);
        }

        $dte = $entrega->dte ?? abort(404);

        return $pdf->pdf($dte)->stream($pdf->nombre($dte));
    }
}
