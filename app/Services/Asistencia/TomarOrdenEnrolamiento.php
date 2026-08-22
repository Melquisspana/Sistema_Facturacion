<?php

namespace App\Services\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El SONDEO: qué le toca hacer a este lector ahora mismo.
 *
 * Es la mitad del truco que permite que el servidor le pida algo al ESP32 sin
 * poder llamarlo. El lector pregunta cada pocos segundos; si hay orden, se la
 * lleva con un token y se pone a capturar.
 *
 * ───────────────────── Cada lector ve SOLO su buzón ─────────────────────
 *
 * La consulta filtra por el lector autenticado. Con dos o tres lectores, ninguno
 * puede recoger —ni siquiera ver— la orden que le tocaba a otro, aunque conozca su
 * identificador.
 *
 * ─────────────────── Reemitir el token es a propósito ───────────────────
 *
 * Si el lector vuelve a sondear una orden que ya tomó, es porque no recibió la
 * respuesta anterior: la red se cortó, se reinició, lo que sea. Se le entrega la
 * misma orden con un token NUEVO, y el anterior deja de valer. Así el reintento
 * funciona sin dejar dos tokens capaces de responder por la misma orden.
 *
 * ─────────────────────────── No decide nada más ───────────────────────────
 *
 * No crea, no falla y no completa. Solo entrega. La orden pasa de `pendiente` a
 * `tomada` porque eso es información real —el lector ya la tiene— y porque
 * distingue «el lector no ha sondeado todavía» de «el lector está esperando el
 * dedo», que es justo lo que necesita saber quien está mirando la pantalla.
 */
class TomarOrdenEnrolamiento
{
    public function __construct(private readonly ExpirarOrdenesVencidas $expirar) {}

    /**
     * @return array{orden: AsistenciaOrdenEnrolamiento, token: string}|null
     *                                                                       null cuando no hay nada que hacer
     */
    public function __invoke(AsistenciaDispositivo $lector): ?array
    {
        // Primero se materializan las vencidas: si no, una orden abandonada se
        // seguiría entregando al lector hasta que alguien la mirara desde la web.
        ($this->expirar)($lector);

        return DB::transaction(function () use ($lector) {
            $orden = AsistenciaOrdenEnrolamiento::query()
                ->deDispositivo($lector->id)
                ->vivas()
                ->with('empleado')
                // `lockForUpdate` para que dos sondeos simultáneos del mismo lector
                // —dos hilos del firmware, un reintento solapado— no emitan dos
                // tokens distintos a la vez y se pisen entre ellos.
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            if ($orden === null) {
                return null;
            }

            // El empleado pudo desactivarse entre la creación y el sondeo. Vale la
            // pena mirarlo acá: grabarle una huella a alguien que ya no marca es
            // trabajo perdido y una plantilla ocupando una ranura para nada.
            if (! $orden->empleado?->activo) {
                $orden->update([
                    'estado' => EstadoOrdenEnrolamiento::Fallida,
                    'motivo_fallo' => MotivoFalloEnrolamiento::EmpleadoNoElegible,
                    'detalle' => MotivoFalloEnrolamiento::EmpleadoNoElegible->explicacion(),
                    'finalizada_at' => Carbon::now(),
                ]);

                return null;
            }

            $token = $orden->emitirToken();

            $orden->update([
                'estado' => EstadoOrdenEnrolamiento::Tomada,
                'tomada_at' => $orden->tomada_at ?? Carbon::now(),
            ]);

            // Se recarga PRIMERO y se enlaza el lector DESPUÉS: `refresh()`
            // descarta las relaciones cargadas, así que al revés se perdería.
            $orden->refresh()->load('empleado');
            $orden->setRelation('dispositivo', $lector);

            return ['orden' => $orden, 'token' => $token];
        });
    }
}
