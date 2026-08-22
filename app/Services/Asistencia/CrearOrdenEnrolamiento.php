<?php

namespace App\Services\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Exceptions\Asistencia\EnrolamientoImposibleException;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pone una orden en el buzón de un lector, con su ranura ya apartada.
 *
 * ─────────────────── Apartar NO es asignar ───────────────────
 *
 * La ranura queda reservada en la propia orden, no en `asistencia_huellas`. La
 * asignación solo nace cuando el AS608 confirma que grabó la plantilla —esa es la
 * regla del encargo— y si el enrolamiento falla, la reserva desaparece con la
 * orden sin dejar ninguna huella fantasma.
 *
 * ─────────────────── Dos defensas contra la concurrencia ───────────────────
 *
 * 1. La selección de {@see SelectorRanura}, que ya excluye asignadas, reservadas y
 *    ocupadas en el sensor. Es la que produce el número.
 * 2. El ÚNICO PARCIAL de la base sobre `(dispositivo, ranura_reservada_uq)`. Es la
 *    que de verdad garantiza.
 *
 * No sobran: entre elegir y escribir hay una ventana en la que otra petición puede
 * quedarse con el mismo número. Cuando eso pasa, la violación del único se atrapa
 * y se REINTENTA con una selección nueva, que ya verá la reserva rival. Devolver
 * un error ahí sería fallarle al usuario por una carrera que el sistema puede
 * resolver solo.
 *
 * El otro único —`(dispositivo, orden_activa_uq)`— impide que un lector tenga dos
 * órdenes vivas: un ESP32 no puede enrolar dos huellas a la vez.
 */
class CrearOrdenEnrolamiento
{
    /** Reintentos ante una carrera por la misma ranura. Tres es de sobra: cada uno ve una reserva más. */
    private const INTENTOS_POR_CARRERA = 3;

    public function __construct(
        private readonly SelectorRanura $selector,
        private readonly ExpirarOrdenesVencidas $expirar,
    ) {}

    /**
     * @param  int|null  $ranuraManual  Escape de «opciones avanzadas». `null` = automática.
     *
     * @throws EnrolamientoImposibleException
     */
    public function __invoke(
        AsistenciaEmpleado $empleado,
        AsistenciaDispositivo $lector,
        ?int $ranuraManual = null,
        ?AsistenciaOrdenEnrolamiento $origen = null,
    ): AsistenciaOrdenEnrolamiento {
        $this->exigirCondiciones($empleado, $lector);

        // Antes de mirar el buzón: una orden abandonada hace media hora no puede
        // bloquear a la siguiente ni seguir apartando su ranura.
        ($this->expirar)($lector);

        if ($activa = AsistenciaOrdenEnrolamiento::query()->deDispositivo($lector->id)->vivas()->first()) {
            throw EnrolamientoImposibleException::ordenActiva($activa);
        }

        return $ranuraManual !== null
            ? $this->conRanuraManual($empleado, $lector, $ranuraManual, $origen)
            : $this->conRanuraAutomatica($empleado, $lector, $origen);
    }

    // ---------------------------------------------------------------- interno

    private function exigirCondiciones(AsistenciaEmpleado $empleado, AsistenciaDispositivo $lector): void
    {
        if (! $empleado->activo) {
            throw EnrolamientoImposibleException::empleadoInactivo();
        }

        // Un lector desactivado no autentica, así que nunca llegaría a sondear su
        // buzón: la orden se quedaría ahí hasta vencer.
        if (! $lector->activo) {
            throw EnrolamientoImposibleException::lectorInactivo($lector);
        }
    }

    private function conRanuraAutomatica(
        AsistenciaEmpleado $empleado,
        AsistenciaDispositivo $lector,
        ?AsistenciaOrdenEnrolamiento $origen,
    ): AsistenciaOrdenEnrolamiento {
        for ($intento = 1; $intento <= self::INTENTOS_POR_CARRERA; $intento++) {
            $ranura = $this->selector->siguienteLibre($lector);

            try {
                return $this->guardar($empleado, $lector, $ranura, manual: false, origen: $origen);
            } catch (UniqueConstraintViolationException $e) {
                // Otra petición ganó la carrera. Se vuelve a elegir: la siguiente
                // selección ya ve su reserva. Si en el último intento sigue
                // chocando, es que algo más grave pasa y el error sube.
                if ($intento === self::INTENTOS_POR_CARRERA) {
                    throw $e;
                }
            }
        }

        // Inalcanzable: el bucle o devuelve o lanza.
        throw EnrolamientoImposibleException::sensorLleno($lector);
    }

    /**
     * El escape de «opciones avanzadas». Elige el número una persona, pero NO se
     * salta ninguna protección: se comprueba contra las tres fuentes igual que la
     * automática, y el único de la base sigue siendo el árbitro.
     */
    private function conRanuraManual(
        AsistenciaEmpleado $empleado,
        AsistenciaDispositivo $lector,
        int $ranura,
        ?AsistenciaOrdenEnrolamiento $origen,
    ): AsistenciaOrdenEnrolamiento {
        if ($motivo = $this->selector->motivoParaNoUsar($lector, $ranura)) {
            throw EnrolamientoImposibleException::ranuraManualInvalida($motivo);
        }

        try {
            return $this->guardar($empleado, $lector, $ranura, manual: true, origen: $origen);
        } catch (UniqueConstraintViolationException) {
            // Acá NO se reintenta con otro número: la persona pidió ESA ranura y
            // darle otra en silencio sería desobedecerla.
            throw EnrolamientoImposibleException::ranuraManualInvalida(
                "La ranura {$ranura} se apartó para otro registro mientras guardábamos. Probá de nuevo."
            );
        }
    }

    private function guardar(
        AsistenciaEmpleado $empleado,
        AsistenciaDispositivo $lector,
        int $ranura,
        bool $manual,
        ?AsistenciaOrdenEnrolamiento $origen,
    ): AsistenciaOrdenEnrolamiento {
        return DB::transaction(fn () => AsistenciaOrdenEnrolamiento::create([
            'asistencia_dispositivo_id' => $lector->id,
            'asistencia_empleado_id' => $empleado->id,
            'estado' => EstadoOrdenEnrolamiento::Pendiente,
            'ranura_reservada' => $ranura,
            'ranura_manual' => $manual,
            'intento' => $origen === null ? 1 : $origen->intento + 1,
            'orden_origen_id' => $origen?->id,
            'expira_at' => Carbon::now()->addMinutes(AsistenciaOrdenEnrolamiento::MINUTOS_DE_VIDA),
            // Puede ser null: los reintentos automáticos los origina el propio
            // lector al reportar un conflicto, no una persona.
            'solicitada_por_user_id' => Auth::id(),
        ]));
    }
}
