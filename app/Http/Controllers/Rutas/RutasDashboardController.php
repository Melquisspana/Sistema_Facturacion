<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use Illuminate\View\View;

/**
 * Inicio del área Rutas / Cobros.
 *
 * Muestra SOLO lo que ya existe de verdad: rutas activas, salidas abiertas y las
 * últimas salidas. Nada de dinero trabado, documentos faltantes, NC pendientes ni
 * predicción de próxima ruta: eso necesita el seguimiento documental, que todavía
 * no existe, y un número inventado en un dashboard se vuelve verdad muy rápido.
 */
class RutasDashboardController extends Controller
{
    public function index(): View
    {
        return view('rutas.dashboard', [
            'rutasActivas' => Ruta::activas()->count(),
            'rutasTotales' => Ruta::count(),
            'planificadas' => SalidaRuta::enEstado(EstadoSalidaRuta::Planificada)->count(),
            'enCurso' => SalidaRuta::enEstado(EstadoSalidaRuta::EnCurso)->count(),
            'salasSinRuta' => ClienteSucursal::whereNull('ruta_id')->where('activo', true)->count(),
            'ultimas' => SalidaRuta::with(['ruta:id,nombre', 'vendedores:id,name'])
                ->orderByDesc('fecha_inicio')
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
