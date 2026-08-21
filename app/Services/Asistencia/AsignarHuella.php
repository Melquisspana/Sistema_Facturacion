<?php

namespace App\Services\Asistencia;

use App\Exceptions\Asistencia\RanuraOcupadaException;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Asignar una ranura del sensor a una persona. Es el ÚNICO camino por el que
 * debería nacer una `asistencia_huellas`.
 *
 * Por qué un servicio y no un `create()` en el controlador o el comando: asignar
 * una ranura decide de quién van a ser las marcaciones que vengan después. Es el
 * acto más sensible del módulo, tiene que quedar auditado siempre, y tiene que
 * comprobar lo mismo desde la consola, desde la futura pantalla y desde el
 * enrolamiento remoto. Tres copias de esa regla acabarían siendo tres reglas.
 *
 * ─────────────────────────────── Dos defensas ───────────────────────────────
 *
 * 1. La CONSULTA de acá, que produce un error legible con el nombre de quien
 *    ocupa la ranura. Es la que ve el usuario.
 * 2. El ÚNICO de la base sobre la columna generada (migración `2026_08_21_090000`).
 *    Es la que de verdad garantiza.
 *
 * No sobran. Entre la consulta y el `INSERT` hay una ventana en la que otra
 * petición puede insertar la suya; con un solo `SELECT` previo, las dos pasarían.
 * Por eso la violación del único se atrapa y se traduce al MISMO error de
 * dominio: quien llama no tiene que saber cuál de las dos defensas actuó.
 *
 * NO toca el sensor. Guardar la plantilla en la ranura N es un acto del AS608;
 * esto solo anota a quién corresponde. Mientras el enrolamiento remoto no exista,
 * el orden correcto es: primero el sensor, después esta anotación.
 */
class AsignarHuella
{
    /**
     * @throws RanuraOcupadaException si la ranura ya tiene una asignación vigente
     */
    public function __invoke(
        AsistenciaEmpleado $empleado,
        AsistenciaDispositivo $dispositivo,
        int $fingerprintId,
    ): AsistenciaHuella {
        $this->exigirRanuraLibre($dispositivo, $fingerprintId);

        try {
            return AsistenciaHuella::create([
                'asistencia_empleado_id' => $empleado->id,
                'asistencia_dispositivo_id' => $dispositivo->id,
                'fingerprint_id' => $fingerprintId,
                'activo' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra petición ganó la carrera entre la comprobación y el INSERT. Se
            // vuelve a leer para poder decir de quién es AHORA la ranura, en vez
            // de devolver un error de base de datos.
            $this->exigirRanuraLibre($dispositivo, $fingerprintId);

            // Inalcanzable salvo que la asignación rival se haya liberado en el
            // intervalo. En ese caso el reintento es legítimo y no hay conflicto.
            throw RanuraOcupadaException::para(
                AsistenciaHuella::query()
                    ->deRanura($dispositivo->id, $fingerprintId)
                    ->latest('id')
                    ->firstOrFail()
            );
        }
    }

    /**
     * ¿Está libre la ranura? Libre = sin asignación ACTIVA. Las históricas no
     * estorban: para eso se cambió el esquema.
     */
    public function ranuraLibre(AsistenciaDispositivo $dispositivo, int $fingerprintId): bool
    {
        return ! AsistenciaHuella::query()
            ->deRanura($dispositivo->id, $fingerprintId)
            ->activas()
            ->exists();
    }

    /** @throws RanuraOcupadaException */
    private function exigirRanuraLibre(AsistenciaDispositivo $dispositivo, int $fingerprintId): void
    {
        $ocupante = AsistenciaHuella::query()
            ->deRanura($dispositivo->id, $fingerprintId)
            ->activas()
            ->with(['empleado', 'dispositivo'])
            ->first();

        if ($ocupante !== null) {
            throw RanuraOcupadaException::para($ocupante);
        }
    }
}
