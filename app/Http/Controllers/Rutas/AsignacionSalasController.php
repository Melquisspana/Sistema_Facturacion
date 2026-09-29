<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Services\Rutas\PropuestaRutas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Asignar salas por cobertura: el sistema propone, el usuario confirma.
 *
 * La propuesta se RECALCULA al aplicar, no se confía en lo que llegó del formulario: si
 * alguien cambió la cobertura entre que se abrió la pantalla y se envió, una sala que ya
 * no tiene propuesta no se mueve, y la ruta destino es siempre la que la cobertura dice
 * en ese momento.
 */
class AsignacionSalasController extends Controller
{
    public function index(Request $request, PropuestaRutas $propuestas): View
    {
        $rutaId = $request->filled('ruta_id') ? $request->integer('ruta_id') : null;

        return view('rutas.asignacion.index', $propuestas->clasificar($rutaId) + [
            'rutas' => Ruta::activas()->withCount('coberturas')->orderBy('nombre')->get(['id', 'nombre']),
            'rutaId' => $rutaId,
        ]);
    }

    public function aplicar(Request $request, PropuestaRutas $propuestas): RedirectResponse
    {
        $datos = $request->validate([
            'sucursales' => ['required', 'array', 'min:1'],
            'sucursales.*' => [Rule::exists('cliente_sucursales', 'id')],
        ], [
            'sucursales.required' => 'No marcaste ninguna sala.',
        ]);

        // Por modelo y no con un update masivo: cada sala deja su rastro de auditoría, y el
        // scope de SoftDeletes deja fuera a las dadas de baja.
        $salas = ClienteSucursal::with('ruta:id,nombre')->whereIn('id', $datos['sucursales'])->get();

        $asignadas = 0;
        $sinPropuesta = 0;

        foreach ($salas as $sala) {
            $ruta = $propuestas->para($sala);

            if ($ruta === null) {
                $sinPropuesta++;

                continue;
            }

            if ($sala->ruta_id === $ruta->id) {
                continue;
            }

            $rutaAnterior = $sala->ruta?->nombre;
            $sala->update(['ruta_id' => $ruta->id]);
            $asignadas++;

            activity('ruta_sala')
                ->performedOn($sala)
                ->causedBy($request->user())
                ->withProperties([
                    'ruta_id' => $ruta->id,
                    'ruta' => $ruta->nombre,
                    'ruta_anterior' => $rutaAnterior,
                    'por_cobertura' => true,
                ])
                ->log('asignó la sala a la ruta');
        }

        $mensaje = match ($asignadas) {
            0 => 'No se movió ninguna sala.',
            1 => '1 sala asignada según la cobertura.',
            default => "{$asignadas} salas asignadas según la cobertura.",
        };

        if ($sinPropuesta > 0) {
            $mensaje .= " {$sinPropuesta} ya no tenían ruta propuesta y quedaron como estaban.";
        }

        return back()->with('status', $mensaje);
    }
}
