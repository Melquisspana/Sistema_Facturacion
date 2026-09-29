<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\RitmoRutas;
use Illuminate\View\View;

/**
 * La pantalla del día a día: qué salidas van en camino y, por cada ruta, cuánto falta
 * para que toque ir ({@see RitmoRutas}) y cuántos CCF esperan ({@see EntregasCcf}). Desde
 * cada tarjeta se sale a la ruta sin pasar por otro formulario.
 */
class RutasDashboardController extends Controller
{
    public function index(RitmoRutas $ritmo, EntregasCcf $entregas): View
    {
        $enCamino = SalidaRuta::query()
            ->whereIn('estado', [EstadoSalidaRuta::EnCurso->value, EstadoSalidaRuta::Planificada->value])
            ->with(['ruta:id,nombre', 'personal:id,nombre', 'entregas.dte:id,numero_orden_compra'])
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [EstadoSalidaRuta::EnCurso->value])
            ->orderBy('fecha_inicio')
            ->get()
            ->map(fn (SalidaRuta $s) => [
                'salida' => $s,
                'resumen' => $entregas->resumen($s->entregas, $entregas->resolucionesAlbaran($s->entregas)),
            ]);

        return view('rutas.dashboard', [
            'filas' => $ritmo->porRuta(),
            'pendientes' => $entregas->pendientesPorRuta(),
            'enCamino' => $enCamino,
            'vendedores' => PersonalRuta::activos()->orderBy('nombre')->get(['id', 'nombre']),
            'salasSinRuta' => ClienteSucursal::whereNull('ruta_id')->where('activo', true)->count(),
        ]);
    }
}
