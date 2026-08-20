<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Resumen\ResumenConfiguracion;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Pantalla Resumen: en qué estado está la configuración del sistema, de un
 * vistazo.
 *
 * ES SOLO LECTURA. No tiene métodos de escritura y no los tendrá: en cuanto un
 * resumen empieza a permitir cambios deja de poder leerse rápido, que es lo único
 * que justifica su existencia. Cada tarjeta enlaza a la pantalla que administra lo
 * suyo cuando esa pantalla existe.
 *
 * No hace red: ver el docblock de {@see ResumenConfiguracion}.
 */
class ResumenController extends Controller
{
    public function index(ResumenConfiguracion $resumen): View
    {
        $tarjetas = $resumen->tarjetas();

        return view('configuracion.resumen.index', [
            'tarjetas' => $tarjetas,
            'atencion' => array_values(array_filter(
                $tarjetas,
                static fn ($t) => $t->estado->requiereAtencion(),
            )),
        ]);
    }
}
