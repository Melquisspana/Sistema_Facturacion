<?php

namespace App\Http\Controllers\Planta;

use App\Http\Controllers\Controller;
use App\Support\Planta\PlantaDashboardQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panel de inicio del área Producción (planta).
 *
 * SOLO LECTURA. Muestra conteos —nunca sumas de cantidad— calculados sobre las
 * tablas `planta_*` y nada más. No escribe, no reconcilia y no consulta ninguna
 * tabla fiscal: quien trabaja en planta no ve DTE, ventas, jobs ni documentos
 * recibidos, y esa separación se verifica espiando el SQL de la petición
 * (PlantaRedireccionTest C4).
 *
 * AQUÍ VIVE LA AUTORIZACIÓN, y por eso el controlador tiene la forma que tiene.
 * Cada indicador se calcula SOLO si el usuario tiene su permiso funcional; sin
 * él, el método de {@see PlantaDashboardQuery} ni siquiera se invoca y la vista
 * recibe `null`. Ocultar la tarjeta con `@can` no bastaría: el servidor habría
 * leído y agregado datos que ese usuario no puede consultar, y además pagado el
 * coste. La vista distingue tres casos y no dos:
 *
 *   - `null`  -> sin permiso: la tarjeta NO se dibuja;
 *   - `0`     -> con permiso y sin datos: se dibuja su estado vacío;
 *   - `> 0`   -> con permiso y con datos: se dibuja el valor.
 *
 * NO se crearon permisos para esto. Los seis que ya reparte el módulo alcanzan,
 * y cada tarjeta usa exactamente el mismo que protege la pantalla a la que
 * enlaza: prometer un enlace que va a responder 403 es peor que no ofrecerlo.
 *
 * El candado del área —auth + interruptor del módulo + `planta.ver`— lo resuelve
 * el middleware de routes/planta.php antes de llegar aquí. Un usuario con
 * `planta.ver` y ningún permiso funcional entra igual: ve la cabecera y un aviso
 * de que no tiene indicadores que consultar, nunca un 403.
 */
class PlantaDashboardController extends Controller
{
    public function index(Request $request, PlantaDashboardQuery $consulta): View
    {
        $usuario = $request->user();

        $puede = fn (string $permiso): bool => (bool) $usuario?->can($permiso);

        $existencias = $puede('planta.existencias.ver') ? $consulta->existencias() : null;

        return view('planta.dashboard', [
            'traslados' => $puede('planta.traslados.ver') ? $consulta->traslados() : null,
            'existencias' => $existencias,
            'recepciones' => $puede('planta.recepciones.ver') ? $consulta->recepcionesEnBorrador() : null,
            'lotes' => $puede('planta.catalogos.ver') ? $consulta->lotesPorVencimiento() : null,
            'ajustes' => $puede('planta.ajustes.ver') ? $consulta->ajustesConfirmadosRecientes() : null,
            // La ventana viaja a la vista para que el rótulo y el enlace del
            // listado digan el mismo número que se consultó.
            'diasVentana' => PlantaDashboardQuery::DIAS_VENTANA,
            'desdeVentana' => PlantaDashboardQuery::inicioDeVentana()->toDateString(),
        ]);
    }
}
