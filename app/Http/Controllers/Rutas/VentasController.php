<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Producto;
use App\Models\Ruta;
use App\Services\Rutas\VentasRutas;
use App\Services\Rutas\VentasRutasExcel;
use Illuminate\Http\Request;

class VentasController extends Controller
{
    public function index(Request $request, VentasRutas $ventas)
    {
        $datos = $ventas->consultar($request->query());
        $chips = [];
        foreach (['ruta' => Ruta::class, 'cliente' => Cliente::class, 'sala' => ClienteSucursal::class, 'producto' => Producto::class] as $clave => $modelo) {
            if (isset($datos['filtros'][$clave])) {
                $consulta = $clave === 'ruta' ? $modelo::query() : $modelo::withTrashed();
                $chips[$clave] = (int) $datos['filtros'][$clave] === 0 ? ($clave === 'ruta' ? 'Sin ruta' : 'Ajustes sin producto') : $consulta->find($datos['filtros'][$clave])?->nombre;
            }
        }

        return view('rutas.ventas.index', $datos + ['chips' => $chips]);
    }

    public function excel(Request $request, VentasRutas $ventas, VentasRutasExcel $excel)
    {
        $datos = $ventas->consultar($request->query());

        return response()->download($excel->generar($datos), 'ventas_'.$datos['periodo']['desde']->toDateString().'_'.$datos['periodo']['hasta']->toDateString().'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
