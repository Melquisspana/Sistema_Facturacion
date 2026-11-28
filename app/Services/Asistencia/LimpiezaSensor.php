<?php

namespace App\Services\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Las huellas SUELTAS de un sensor: plantillas grabadas en el AS608 que el
 * sistema no conoce. Quedan cuando se borran filas de la base —pruebas viejas,
 * una restauración— y el sensor sigue reconociendo esos dedos: la marca llega con
 * una ranura que nadie tiene y se pierde con un 404.
 *
 * ──────────────────────── Qué es «suelta» ────────────────────────
 *
 * Ocupada en el sensor (según el último índice) y, a la vez:
 *   - sin NINGUNA fila en `asistencia_huellas` de ese lector, ni activa ni de
 *     baja. Una fila de baja es historial: esa ranura se conserva;
 *   - sin una orden de enrolamiento abierta que la tenga reservada. Se cuenta
 *     la orden abierta aunque haya vencido y nadie la haya cerrado todavía: el
 *     lector pudo haber grabado y estar por avisar.
 */
class LimpiezaSensor
{
    /** Cuánto espera una limpieza confirmada a que el lector mande su índice. */
    public const DIAS_PENDIENTE = 7;

    /**
     * Deja pendiente que el lector borre estas ranuras la próxima vez que mande
     * su índice. Vive en caché, por lector: si nadie la usa, vence sola.
     *
     * @param  array<int, int>  $ranuras
     */
    public function programar(AsistenciaDispositivo $lector, array $ranuras, User $responsable): void
    {
        $ranuras = $this->ordenadas(array_map('intval', $ranuras));

        Cache::put($this->clave($lector), $ranuras, Carbon::now()->addDays(self::DIAS_PENDIENTE));

        activity('asistencia')
            ->performedOn($lector)
            ->causedBy($responsable)
            ->event('limpieza_sensor')
            ->withProperties(['codigo' => $lector->codigo, 'ranuras' => $ranuras])
            ->log('dejó pendiente borrar huellas sueltas del sensor');
    }

    /**
     * Lo que el lector tiene que borrar AHORA, justo después de guardar el índice
     * que acaba de mandar. Vacío si no hay una limpieza pendiente.
     *
     * Las sueltas se vuelven a calcular en este momento y se cruzan con la lista
     * confirmada: una ranura que entretanto se registró o se reservó ya no sale.
     * Y nunca sale una que no estaba en la confirmación.
     *
     * Cuando ya no queda nada por borrar, la pendiente se cierra sola y se audita.
     *
     * @return array<int, int>
     */
    public function paraBorrar(AsistenciaDispositivo $lector): array
    {
        $pendiente = Cache::get($this->clave($lector));

        if (! is_array($pendiente) || $pendiente === []) {
            return [];
        }

        $borrar = $this->ordenadas(array_intersect($pendiente, $this->sueltas($lector)));

        if ($borrar !== []) {
            return $borrar;
        }

        Cache::forget($this->clave($lector));

        $ocupadas = $lector->ranurasOcupadasEnSensor();

        activity('asistencia')
            ->performedOn($lector)
            ->event('limpieza_sensor_hecha')
            ->withProperties([
                'codigo' => $lector->codigo,
                'borradas' => $this->ordenadas(array_diff($pendiente, $ocupadas)),
                // Siguen en el sensor porque entretanto se registraron o reservaron.
                'conservadas' => $this->ordenadas(array_intersect($pendiente, $ocupadas)),
            ])
            ->log('terminó la limpieza de huellas sueltas del sensor');

        return [];
    }

    /** @return array<int, int> ordenadas */
    public function sueltas(AsistenciaDispositivo $lector): array
    {
        $reservadas = AsistenciaOrdenEnrolamiento::query()
            ->deDispositivo($lector->id)
            ->whereIn('estado', EstadoOrdenEnrolamiento::vivos())
            ->pluck('ranura_reservada')
            ->map(fn ($ranura) => (int) $ranura)
            ->all();

        return $this->ordenadas(array_diff(
            $lector->ranurasOcupadasEnSensor(),
            $this->conFila($lector),
            $reservadas,
        ));
    }

    /**
     * Ocupadas en el sensor cuya única historia son filas dadas de baja. Se
     * muestran aparte y NO se borran.
     *
     * @return array<int, int> ordenadas
     */
    public function deBaja(AsistenciaDispositivo $lector): array
    {
        $activas = AsistenciaHuella::query()
            ->where('asistencia_dispositivo_id', $lector->id)
            ->activas()
            ->pluck('fingerprint_id')
            ->map(fn ($ranura) => (int) $ranura)
            ->all();

        return $this->ordenadas(array_intersect(
            $lector->ranurasOcupadasEnSensor(),
            array_diff($this->conFila($lector), $activas),
        ));
    }

    private function clave(AsistenciaDispositivo $lector): string
    {
        return 'asistencia:limpieza-sensor:'.$lector->id;
    }

    /** @return array<int, int> */
    private function conFila(AsistenciaDispositivo $lector): array
    {
        return AsistenciaHuella::query()
            ->where('asistencia_dispositivo_id', $lector->id)
            ->pluck('fingerprint_id')
            ->map(fn ($ranura) => (int) $ranura)
            ->all();
    }

    /**
     * @param  array<int, int>  $ranuras
     * @return array<int, int>
     */
    private function ordenadas(array $ranuras): array
    {
        $ranuras = array_values(array_unique($ranuras));
        sort($ranuras);

        return $ranuras;
    }
}
