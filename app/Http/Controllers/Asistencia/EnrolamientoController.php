<?php

namespace App\Http\Controllers\Asistencia;

use App\Exceptions\Asistencia\EnrolamientoImposibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\IniciarEnrolamientoRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Services\Asistencia\CrearOrdenEnrolamiento;
use App\Services\Asistencia\ResolverEnrolamiento;
use App\Services\Asistencia\TomarOrdenEnrolamiento;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * El lado HUMANO del enrolamiento: iniciar y cancelar desde la ficha del empleado.
 *
 * ─────────────── Este controlador no toca el sensor ni la ranura ───────────────
 *
 * Pone la orden en el buzón del lector y ya. Elegir la ranura, apartarla,
 * entregarla y confirmarla son actos de {@see CrearOrdenEnrolamiento},
 * {@see TomarOrdenEnrolamiento} y
 * {@see ResolverEnrolamiento}. Un `where` de ranuras acá sería una segunda regla
 * de selección, y solo una está respaldada por el único de la base.
 *
 * ───────────────────── Ningún navegador es un lector ─────────────────────
 *
 * Desde acá NO se puede completar una orden ni fingir un resultado: esas rutas
 * viven en /api y exigen el token del lector, que la web no conoce y nunca
 * muestra. Lo único que una persona puede hacer es pedir el registro y cancelarlo.
 */
class EnrolamientoController extends Controller
{
    /** Pide al lector que grabe la huella de esta persona. */
    public function store(
        IniciarEnrolamientoRequest $request,
        AsistenciaEmpleado $empleado,
        CrearOrdenEnrolamiento $crear,
    ): RedirectResponse {
        $lector = AsistenciaDispositivo::findOrFail($request->integer('asistencia_dispositivo_id'));

        try {
            $crear($empleado, $lector, $request->ranuraManual());
        } catch (EnrolamientoImposibleException $e) {
            // El mensaje del dominio ya dice qué pasó y qué hacer. Reescribirlo acá
            // lo dejaría distinto del que vería cualquier otra vía.
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('status',
            "Listo. Pedile a {$empleado->nombreCorto()} que coloque el dedo en «{$lector->nombre}» "
            .'cuando la pantalla lo indique.'
        );
    }

    /**
     * Cancela la orden viva. No detiene la captura física: si el sensor ya grabó,
     * puede quedar una plantilla sin dueño aunque el servidor cierre la orden.
     */
    public function destroy(
        AsistenciaEmpleado $empleado,
        AsistenciaOrdenEnrolamiento $orden,
        ResolverEnrolamiento $resolver,
    ): RedirectResponse {
        // La orden tiene que ser de esta persona: sin esto, cualquiera con permiso
        // podría cancelar el registro de otra desde una URL a mano.
        abort_unless($orden->asistencia_empleado_id === $empleado->id, Response::HTTP_NOT_FOUND);

        if ($orden->estado->esFinal()) {
            return back()->with('error', 'Ese registro ya había terminado; no se canceló nada.');
        }

        $resolver->cancelar($orden);

        return back()->with('status', 'Registro de huella cancelado. Si el lector ya estaba capturando, puede haber quedado una huella guardada sin dueño.');
    }
}
