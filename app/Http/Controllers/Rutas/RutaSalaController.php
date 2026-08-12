<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asignación de salas a la ruta HABITUAL.
 *
 * Lo único que escribe es `cliente_sucursales.ruta_id`. NO crea, edita ni da de
 * baja salas, y no toca su nombre, código, cliente ni ningún dato fiscal.
 *
 * Nada se mueve sin acción explícita del usuario: no hay asignación automática
 * por nombre, por departamento ni por parecido.
 *
 * Auditoría: cada asignación/desasignación queda en activity_log contra la SALA
 * —que es la fila que cambió— con el nombre de la ruta en las propiedades.
 */
class RutaSalaController extends Controller
{
    /**
     * Asigna una o varias salas a la ruta. Acepta varias porque el buscador del
     * detalle deja marcar los resultados de la página, y obligar a un POST por
     * sala haría el trabajo interminable con 135 sucursales.
     */
    public function store(Request $request, Ruta $ruta): RedirectResponse
    {
        $datos = $request->validate([
            'sucursales' => ['required', 'array', 'min:1'],
            // `exists` sin más: las dadas de baja no están en la tabla para el
            // scope de SoftDeletes, pero sí para la validación, así que el filtro
            // real es la consulta de abajo.
            'sucursales.*' => [Rule::exists('cliente_sucursales', 'id')],
        ], [
            'sucursales.required' => 'No elegiste ninguna sala.',
        ]);

        // Se releen por modelo (no un update masivo) para que cada fila dispare su
        // evento de auditoría y para no tocar NUNCA una sucursal dada de baja: el
        // scope de SoftDeletes las deja fuera de este `get`.
        $salas = ClienteSucursal::whereIn('id', $datos['sucursales'])->get();

        $movidas = 0;
        foreach ($salas as $sala) {
            if ($sala->ruta_id === $ruta->id) {
                continue; // ya estaba: no se reescribe ni se audita de más
            }

            $rutaAnterior = $sala->ruta?->nombre;
            $sala->update(['ruta_id' => $ruta->id]);
            $movidas++;

            activity('ruta_sala')
                ->performedOn($sala)
                ->causedBy($request->user())
                ->withProperties([
                    'ruta_id' => $ruta->id,
                    'ruta' => $ruta->nombre,
                    'ruta_anterior' => $rutaAnterior,
                ])
                ->log('asignó la sala a la ruta');
        }

        return back()->with('status', match (true) {
            $movidas === 0 => 'Esas salas ya estaban en la ruta.',
            $movidas === 1 => '1 sala asignada a la ruta.',
            default => "{$movidas} salas asignadas a la ruta.",
        });
    }

    /** Quita la sala de la ruta: deja `ruta_id` en null. No borra la sala. */
    public function destroy(Request $request, Ruta $ruta, ClienteSucursal $sucursal): RedirectResponse
    {
        // La sala tiene que ser de ESTA ruta: si no, el enlace está desactualizado
        // o alguien está probando ids a mano.
        abort_unless($sucursal->ruta_id === $ruta->id, 404);

        $sucursal->update(['ruta_id' => null]);

        activity('ruta_sala')
            ->performedOn($sucursal)
            ->causedBy($request->user())
            ->withProperties(['ruta_id' => $ruta->id, 'ruta' => $ruta->nombre])
            ->log('quitó la sala de la ruta');

        return back()->with('status', "«{$sucursal->nombre}» ya no pertenece a la ruta.");
    }
}
