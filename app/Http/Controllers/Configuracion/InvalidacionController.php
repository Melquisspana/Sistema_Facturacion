<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Fiscal\InventarioFiscal;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Facturación electrónica → Invalidación.
 *
 * Anular ante el Ministerio de Hacienda un documento ya aceptado. SOLO LECTURA y
 * sin ninguna acción: esta pantalla no invalida nada y no tiene por dónde
 * hacerlo. Muestra sus candados, quién figura como responsable y solicitante del
 * evento, y qué documentos están blindados para que nunca puedan invalidarse.
 */
class InvalidacionController extends Controller
{
    public function index(InventarioFiscal $inventario): View
    {
        return view('configuracion.fiscal.invalidacion', [
            'candados' => $inventario->candados()['Invalidación'],
            'personas' => $inventario->personasInvalidacion(),
            'tecnicos' => $inventario->tecnicosInvalidacion(),
        ]);
    }
}
