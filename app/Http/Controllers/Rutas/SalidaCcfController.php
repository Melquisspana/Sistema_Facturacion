<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Services\Dte\DtePdfService;
use Symfony\Component\HttpFoundation\Response;

/**
 * El PDF de un CCF de la salida: al tocar la tarjeta se DESCARGA (pedido de los vendedores,
 * 04/11/2026: lo guardan y lo mandan desde el celular). Es la misma representación de
 * Facturación ({@see DtePdfService}).
 *
 * Solo sirve CCF que van en ESTA salida (la entrega se busca dentro de la salida: otra da
 * 404). Basta `rutas.ver`, que ya ve la hoja completa. El vendedor baja los suyos desde «Por
 * entregar». Un CCF invalidado sale con su cinta de «invalidado».
 */
class SalidaCcfController extends Controller
{
    public function show(SalidaRuta $salida, SalidaRutaEntrega $entrega, DtePdfService $pdf): Response
    {
        $dte = $entrega->dte ?? abort(404);

        return $pdf->pdf($dte)->download($pdf->nombre($dte));
    }
}
