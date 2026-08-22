<?php

namespace App\Services\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Exceptions\Asistencia\EnrolamientoImposibleException;
use App\Exceptions\Asistencia\RanuraOcupadaException;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CIERRA una orden: el lector reporta que grabó, o que no pudo.
 *
 * Es el único sitio donde una orden pasa a `completada` y, con ella, donde nace la
 * `asistencia_huella`. Todo lo anterior —crear, sondear, reportar progreso— no
 * escribe ninguna asignación.
 *
 * ──────────────────────────── IDEMPOTENTE ────────────────────────────
 *
 * El ESP32 está detrás de una red doméstica: puede grabar la plantilla, mandar el
 * resultado y perder la respuesta. Si reintenta, tiene que obtener **el mismo
 * desenlace**, no un error ni una segunda asignación.
 *
 * Por eso una orden ya finalizada no se vuelve a procesar: se devuelve lo que pasó
 * la primera vez. Sin esto, un reintento con éxito crearía dos huellas para la
 * misma ranura —y el único de la Fase 1 lo rechazaría, convirtiendo un
 * enrolamiento correcto en un fallo—.
 *
 * ────────────────── La asignación pasa por AsignarHuella ──────────────────
 *
 * No se escribe `asistencia_huellas` a mano. Se llama al servicio de la Fase 1,
 * que comprueba la ranura, respeta el historial —una ranura reutilizada produce
 * una fila NUEVA, jamás toca la anterior— y deja auditoría. Duplicar esa regla acá
 * sería tener dos.
 *
 * ─────────────── El conflicto con una plantilla heredada ───────────────
 *
 * Si el lector avisa de que la ranura reservada ya tenía una plantilla que el
 * servidor no conocía, **no se sobrescribe nada**. Se guarda el índice real que el
 * lector reporta, la orden falla, y se crea una orden NUEVA con otra ranura —ya
 * excluyendo la que resultó ocupada—. Es la «nueva operación segura» del encargo,
 * acotada a {@see AsistenciaOrdenEnrolamiento::MAX_INTENTOS} para que un sensor
 * lleno de plantillas heredadas no genere una cadena infinita.
 */
class ResolverEnrolamiento
{
    public function __construct(
        private readonly AsignarHuella $asignar,
        private readonly CrearOrdenEnrolamiento $crear,
        private readonly SincronizarIndiceSensor $sincronizar,
    ) {}

    /**
     * El lector confirma que grabó la plantilla.
     *
     * @return AsistenciaOrdenEnrolamiento la orden ya finalizada (o la que ya lo estaba)
     */
    public function completar(AsistenciaOrdenEnrolamiento $orden, int $fingerprintId): AsistenciaOrdenEnrolamiento
    {
        if ($orden->estado->esFinal()) {
            return $orden;   // reintento: mismo desenlace, sin tocar nada
        }

        if ($orden->estaVencida()) {
            return $this->fallar($orden, MotivoFalloEnrolamiento::Expirada);
        }

        // Le dijimos exactamente dónde grabar. Si dice haber grabado en otro sitio,
        // no se asocia nada: o el firmware improvisó —que es justo lo que la
        // decisión de diseño prohíbe— o el mensaje viene corrupto.
        if ($fingerprintId !== $orden->ranura_reservada) {
            return $this->fallar(
                $orden,
                MotivoFalloEnrolamiento::RanuraNoCoincide,
                "Se reservó la ranura {$orden->ranura_reservada} y el lector reportó la {$fingerprintId}.",
            );
        }

        if (! $orden->empleado?->activo) {
            return $this->fallar($orden, MotivoFalloEnrolamiento::EmpleadoNoElegible);
        }

        return DB::transaction(function () use ($orden, $fingerprintId) {
            try {
                $huella = ($this->asignar)($orden->empleado, $orden->dispositivo, $fingerprintId);
            } catch (RanuraOcupadaException $e) {
                // Entre la reserva y la confirmación alguien asignó esa ranura. El
                // único de la base lo detectó y NO se creó ninguna huella.
                return $this->fallar($orden, MotivoFalloEnrolamiento::RanuraYaAsignada, $e->getMessage());
            }

            $orden->update([
                'estado' => EstadoOrdenEnrolamiento::Completada,
                'asistencia_huella_id' => $huella->id,
                'motivo_fallo' => null,
                'detalle' => null,
                'finalizada_at' => Carbon::now(),
            ]);

            return $orden->refresh();
        });
    }

    /**
     * El lector reporta que no pudo.
     *
     * @param  array{capacidad?: int, ocupadas?: array<int, int>}|null  $indiceSensor
     *                                                                                 lo que el AS608 dice tener, cuando el motivo es un conflicto de ranura
     */
    public function fallarDesdeDispositivo(
        AsistenciaOrdenEnrolamiento $orden,
        MotivoFalloEnrolamiento $motivo,
        ?string $detalle = null,
        ?array $indiceSensor = null,
    ): AsistenciaOrdenEnrolamiento {
        if ($orden->estado->esFinal()) {
            return $orden;   // reintento: mismo desenlace
        }

        // El índice llega junto al fallo por conflicto de ranura, y se guarda ANTES
        // de fallar: así el reintento que viene a continuación ya elige excluyendo
        // la plantilla heredada que acaba de descubrirse.
        if ($indiceSensor !== null && isset($indiceSensor['capacidad'], $indiceSensor['ocupadas'])) {
            ($this->sincronizar)($orden->dispositivo, (int) $indiceSensor['capacidad'], $indiceSensor['ocupadas']);
        }

        $orden = $this->fallar($orden, $motivo, $detalle);

        if ($motivo === MotivoFalloEnrolamiento::RanuraOcupadaEnSensor) {
            $orden->setRelation('reintento', $this->reintentar($orden));
        }

        return $orden;
    }

    /** Alguien la cancela desde la web. */
    public function cancelar(AsistenciaOrdenEnrolamiento $orden): AsistenciaOrdenEnrolamiento
    {
        if ($orden->estado->esFinal()) {
            return $orden;
        }

        $orden->update([
            'estado' => EstadoOrdenEnrolamiento::Cancelada,
            'motivo_fallo' => MotivoFalloEnrolamiento::CanceladaPorOperador,
            'detalle' => MotivoFalloEnrolamiento::CanceladaPorOperador->explicacion(),
            'finalizada_at' => Carbon::now(),
        ]);

        return $orden->refresh();
    }

    // ---------------------------------------------------------------- interno

    private function fallar(
        AsistenciaOrdenEnrolamiento $orden,
        MotivoFalloEnrolamiento $motivo,
        ?string $detalle = null,
    ): AsistenciaOrdenEnrolamiento {
        $orden->update([
            'estado' => $motivo === MotivoFalloEnrolamiento::Expirada
                ? EstadoOrdenEnrolamiento::Expirada
                : EstadoOrdenEnrolamiento::Fallida,
            'motivo_fallo' => $motivo,
            'detalle' => $detalle ?? $motivo->explicacion(),
            'finalizada_at' => Carbon::now(),
        ]);

        return $orden->refresh();
    }

    /**
     * Reserva OTRA ranura en una orden nueva, tras descubrir que la anterior tenía
     * una plantilla heredada.
     *
     * Devuelve null si se agotaron los intentos o si ya no queda ninguna libre. No
     * se propaga la excepción: la orden original ya falló con su motivo, y que el
     * reintento no salga no debe convertir una respuesta correcta al lector en un
     * error HTTP.
     */
    private function reintentar(AsistenciaOrdenEnrolamiento $orden): ?AsistenciaOrdenEnrolamiento
    {
        if ($orden->intento >= AsistenciaOrdenEnrolamiento::MAX_INTENTOS) {
            return null;
        }

        try {
            return ($this->crear)(
                $orden->empleado,
                $orden->dispositivo->refresh(),
                // El reintento vuelve a ser AUTOMÁTICO aunque el original fuera
                // manual: la ranura que la persona eligió resultó estar ocupada en
                // el sensor, así que repetirla llevaría al mismo choque.
                ranuraManual: null,
                origen: $orden,
            );
        } catch (EnrolamientoImposibleException) {
            return null;
        }
    }
}
