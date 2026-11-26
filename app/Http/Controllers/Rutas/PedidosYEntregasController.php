<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Services\Rutas\PedidosYEntregas;
use App\Support\HoraNegocio;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Pedidos y entregas» ({@see PedidosYEntregas}): la pantalla de solo mirar de la dueña. El
 * período va en la URL (`?periodo=hoy|ayer|semana`) para que «atrás» funcione; cualquier
 * otro valor es hoy. La ruta exige `rutas.pedidos-entregas.ver` (Jefatura y Administrador).
 */
class PedidosYEntregasController extends Controller
{
    public function index(Request $request, PedidosYEntregas $pedidosYEntregas): View
    {
        $periodo = $request->query('periodo');

        return view('rutas.pedidos-entregas', $pedidosYEntregas->calcular(is_string($periodo) ? $periodo : 'hoy') + [
            'actualizado' => HoraNegocio::ahora(),
        ]);
    }
}
