<?php

namespace App\Services\Asistencia;

use App\Models\Asistencia\AsistenciaHuella;
use Illuminate\Support\Carbon;

/**
 * Liberar una ranura: la asignación deja de estar vigente y el número queda
 * disponible para otra persona.
 *
 * QUÉ NO HACE, Y ES LO IMPORTANTE:
 *
 *  - NO borra la fila. Las marcaciones ya escritas apuntan a ella; borrarla las
 *    dejaría sin saber con qué asignación se hicieron (`nullOnDelete`).
 *  - NO le cambia el empleado. Esa fila es un hecho del pasado: «la ranura 1 fue
 *    de Ana». Reasignarla reescribiría la historia de las marcaciones de Ana.
 *  - NO toca el sensor. Borrar la plantilla del AS608 es un acto físico aparte.
 *    Mientras no exista el enrolamiento remoto, el orden correcto es: liberar
 *    acá, borrar en el sensor, y recién entonces asignarla a otra persona. Si se
 *    invierte, el sensor seguiría reconociendo el dedo viejo y lo resolvería a la
 *    persona NUEVA.
 *
 * Lo único que cambia es `activo` (y con él la columna generada que libera el
 * único de la base) más `liberada_at`, que deja fechado el final del período.
 *
 * IDEMPOTENTE: liberar algo ya liberado no vuelve a escribir ni a auditar, y
 * sobre todo no pisa el `liberada_at` original con una fecha nueva. La primera
 * liberación es la que cierra el período; una segunda llamada no lo mueve.
 */
class LiberarHuella
{
    /** @return bool `true` si esta llamada la liberó; `false` si ya lo estaba. */
    public function __invoke(AsistenciaHuella $huella, ?Carbon $momento = null): bool
    {
        if ($huella->estaLiberada()) {
            return false;
        }

        // Un solo save: `activo` y `liberada_at` van juntos o no van. La columna
        // generada de la base se recalcula en el mismo UPDATE, así que la ranura
        // queda libre exactamente cuando la asignación deja de estar vigente —no
        // hay un instante intermedio en el que una cosa sea cierta y la otra no.
        $huella->forceFill([
            'activo' => false,
            'liberada_at' => $momento ?? Carbon::now(),
        ])->save();

        return true;
    }
}
