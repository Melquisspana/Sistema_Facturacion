<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Ruta;
use App\Services\Rutas\VentasRutas;
use App\Services\Rutas\VentasRutasExcel;
use Barryvdh\DomPDF\Facade\Pdf;
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

        return response()->download($excel->generar($datos), 'ventas-'.($datos['canal'] === 'exportacion' ? 'exportacion' : 'nacionales').'-'.$datos['periodo']['hasta']->format('Y-m').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    public function pdf(Request $request, VentasRutas $ventas)
    {
        $datos = $ventas->consultar($request->query());

        return Pdf::loadView('rutas.ventas.pdf', $datos + ['empresa' => Empresa::where('activo', true)->first()])
            ->setPaper('a4', 'portrait')->setOption('enable_font_subsetting', true)
            ->download('ventas-'.($datos['canal'] === 'exportacion' ? 'exportacion' : 'nacionales').'-'.$datos['periodo']['hasta']->format('Y-m').'.pdf');
    }
}
