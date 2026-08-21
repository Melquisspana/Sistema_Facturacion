<?php

namespace App\Http\Controllers\Asistencia;

use App\Exceptions\Asistencia\RanuraOcupadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\AsignarHuellaRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\LiberarHuella;
use Illuminate\Http\RedirectResponse;

/**
 * Asignar y liberar ranuras del sensor.
 *
 * El controlador NO tiene ni una regla de asistencia: llama a
 * {@see AsignarHuella} y a {@see LiberarHuella} —los mismos servicios que usa la
 * consola— y traduce el desenlace a un mensaje. Es deliberado: la comprobación de
 * ranura ocupada, la auditoría y el respeto al historial son reglas del dominio, y
 * si vivieran acá la consola tendría las suyas propias.
 *
 * ────────────────────── Lo que esta pantalla NO hace ──────────────────────
 *
 * No enrola nada. Guardar la plantilla del dedo en la ranura N es un acto FÍSICO
 * del AS608: hoy se hace en el sensor y aquí solo se ANOTA a quién corresponde.
 * Tampoco manda órdenes al ESP32 — eso llega en el enrolamiento remoto.
 *
 * Por eso el orden correcto al reutilizar una ranura sigue siendo:
 * liberar acá → borrar la plantilla en el sensor → asignar a la persona nueva.
 * Invertir los dos últimos deja el sensor reconociendo el dedo viejo y
 * resolviéndolo a la persona NUEVA, y el sistema no puede detectarlo porque solo
 * le llega un número.
 */
class HuellaController extends Controller
{
    /** Asigna una ranura a la persona. La ranura debe estar LIBRE (sin asignación vigente). */
    public function store(
        AsignarHuellaRequest $request,
        AsistenciaEmpleado $empleado,
        AsignarHuella $asignar,
    ): RedirectResponse {
        $lector = AsistenciaDispositivo::findOrFail($request->integer('asistencia_dispositivo_id'));
        $ranura = $request->integer('fingerprint_id');

        if (! $lector->activo) {
            return back()->with('error', "El lector «{$lector->nombre}» está desactivado: una ranura suya no podría marcar nada.");
        }

        try {
            $asignar($empleado, $lector, $ranura);
        } catch (RanuraOcupadaException $e) {
            // El mensaje del dominio ya dice QUIÉN ocupa la ranura y qué hacer.
            // Reescribirlo acá lo dejaría distinto del que da la consola.
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('status',
            "Ranura {$ranura} del lector «{$lector->nombre}» asignada a {$empleado->nombreCompleto()}. "
            .'Recordá que la plantilla del dedo se guarda en el sensor, no acá.'
        );
    }

    /**
     * Libera la ranura: la asignación deja de estar vigente y el número queda
     * disponible. NO borra la fila ni le cambia el empleado — las marcaciones que
     * ya ocurrieron siguen apuntando a ella.
     */
    public function liberar(AsistenciaHuella $huella, LiberarHuella $liberar): RedirectResponse
    {
        $huella->loadMissing(['empleado', 'dispositivo']);

        $ranura = $huella->fingerprint_id;
        $lector = $huella->dispositivo?->nombre ?? 'lector desconocido';

        if (! $liberar($huella)) {
            return back()->with('error', 'Esa asignación ya estaba liberada; no se cambió nada.');
        }

        return back()->with('status',
            "Ranura {$ranura} del lector «{$lector}» liberada. Queda en el historial de "
            .($huella->empleado?->nombreCompleto() ?? 'la persona').'. '
            .'Borrá la plantilla en el sensor ANTES de asignarla a otra persona.'
        );
    }
}
