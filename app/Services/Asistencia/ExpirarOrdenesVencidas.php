<?php

namespace App\Services\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use Illuminate\Support\Carbon;

/**
 * Materializa las órdenes vencidas: las que siguen con estado vivo pero cuyo
 * `expira_at` ya pasó.
 *
 * ───────────────── Por qué al leer y no con un cron ─────────────────
 *
 * Este proyecto no tiene el scheduler corriendo. Una orden que dependiera de un
 * job para vencer podría ejecutarse horas después de haberse abandonado: el
 * empleado ya se fue, nadie está mirando, y el lector grabaría una huella que
 * alguien pidió por la mañana.
 *
 * En vez de eso, la caducidad se aplica justo ANTES de las dos operaciones a las
 * que le importa —crear una orden y sondear— que son exactamente los momentos en
 * que una orden zombi estorbaría. El resto del tiempo su estado en la tabla puede
 * estar «desactualizado» y da igual: `estaViva()` y el alcance `vivas()` ya
 * comparan contra el reloj, así que nadie la ve como viva.
 *
 * El efecto que sí importa es liberar las dos unicidades parciales —el buzón del
 * lector y la reserva de la ranura—, y eso exige escribir el estado.
 */
class ExpirarOrdenesVencidas
{
    /**
     * Vence las órdenes pasadas de un lector.
     *
     * @return int cuántas se materializaron
     */
    public function __invoke(AsistenciaDispositivo $lector): int
    {
        $vencidas = AsistenciaOrdenEnrolamiento::query()
            ->deDispositivo($lector->id)
            ->vencidas()
            ->get();

        foreach ($vencidas as $orden) {
            // Con `update()` y no en masa: cada transición tiene que pasar por el
            // modelo para que quede su línea de auditoría. Son pocas por
            // definición —a lo sumo una viva por lector— así que el coste es nulo.
            $orden->update([
                'estado' => EstadoOrdenEnrolamiento::Expirada,
                'motivo_fallo' => MotivoFalloEnrolamiento::Expirada,
                'detalle' => MotivoFalloEnrolamiento::Expirada->explicacion(),
                'finalizada_at' => Carbon::now(),
            ]);
        }

        return $vencidas->count();
    }
}
