<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\RitmoRutas;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La pantalla del día a día: qué salidas van en camino y, por cada ruta, cuánto falta
 * para que toque ir ({@see RitmoRutas}) y cuántos CCF esperan ({@see EntregasCcf}). Desde
 * cada tarjeta se sale a la ruta sin pasar por otro formulario.
 */
class RutasDashboardController extends Controller
{
    public function index(Request $request, RitmoRutas $ritmo, EntregasCcf $entregas): View
    {
        // El jefe de vendedores aterriza acá y no en «Mi salida»: si va en una salida en
        // curso, se le ofrece el enlace.
        $usuario = $request->user();
        $vaEnUnaSalida = $usuario->can('rutas.entregas.registrar')
            && SalidaRuta::enEstado(EstadoSalidaRuta::EnCurso)
                ->whereHas('personal', fn ($q) => $q->where('rutas_personal.user_id', $usuario->id)->where('rutas_personal.activo', true))
                ->exists();

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
            'vaEnUnaSalida' => $vaEnUnaSalida,
        ]);
    }
}
