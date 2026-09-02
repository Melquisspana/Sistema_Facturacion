<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Fiscal\InventarioFiscal;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Facturación electrónica → Parámetros fiscales.
 *
 * Impuestos, umbrales y valores por defecto con los que nace un documento.
 * SOLO LECTURA y sin ninguna acción: no hay nada que probar aquí.
 *
 * Es la pantalla donde la clasificación importa más, porque mezcla dos cosas que
 * se parecen y no lo son: la tasa del IVA no es una preferencia de la empresa
 * —es la ley— y la forma de pago por defecto sí lo es.
 */
class ParametrosFiscalesController extends Controller
{
    public function index(InventarioFiscal $inventario): View
    {
        return view('configuracion.fiscal.parametros', [
            'parametros' => $inventario->parametros(),
            'exportacion' => $inventario->exportacion(),
            'empresaExportadora' => $inventario->empresaExportadora(),
        ]);
    }
}
