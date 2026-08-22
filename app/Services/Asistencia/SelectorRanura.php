<?php

namespace App\Services\Asistencia;

use App\Exceptions\Asistencia\EnrolamientoImposibleException;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;

/**
 * QUÉ RANURA usar para la próxima huella, sin que nadie la escriba a mano.
 *
 * ──────────────── La verdad está partida entre dos sistemas ────────────────
 *
 * Y este servicio existe porque asumirlo es la única forma de acertar:
 *
 *   la BASE sabe    -> qué ranuras están ASIGNADAS (huellas activas)
 *                      qué ranuras están RESERVADAS (órdenes vivas)
 *   el AS608 sabe   -> qué ranuras tienen PLANTILLA FÍSICA
 *
 * Se excluye la UNIÓN de las tres. Quedarse solo con las dos primeras es lo que
 * hace que un sensor con plantillas heredadas —de antes de que existiera este
 * sistema, o de un enrolamiento manual— reciba una escritura encima de algo que
 * ya estaba.
 *
 * ─────────────── Sin sincronizar no se reserva: se dice que no ───────────────
 *
 * Si el lector nunca reportó su índice, este servicio NO elige. Ni con un valor
 * por defecto «razonable»: la capacidad varía entre modelos de AS608 y las
 * plantillas heredadas son invisibles. Reservar a ciegas produce un fallo que
 * ocurre delante del empleado, con el dedo puesto; negarse produce un mensaje que
 * se lee antes de empezar.
 */
class SelectorRanura
{
    /**
     * Primera ranura del sensor. Los AS608 numeran desde cero, y este lector lo
     * confirmó de dos formas independientes: `storeModel(0)` devolvió OK y el
     * barrido posterior releyó esa ranura como ocupada. Escribir y releer son
     * caminos distintos del protocolo y los dos aceptan la página 0.
     *
     * Vive acá, en una constante y no repartida por el código, porque es el único
     * detalle de este cálculo que depende del modelo: si un sensor resultara
     * numerar desde 1, se cambia este número y nada más.
     *
     * Para que eso último sea VERDAD y no una intención, el tope superior se
     * deriva siempre de {@see self::ranuraMaxima()} en vez de repetir `< capacidad`
     * por ahí. Antes no era así: con `RANURA_MINIMA = 1` el rango efectivo habría
     * pasado a 1..capacidad-1 y la última ranura del sensor habría quedado
     * inalcanzable en silencio, sin que ninguna prueba lo notara.
     */
    public const RANURA_MINIMA = 0;

    /**
     * La ÚLTIMA ranura válida de un sensor con esta capacidad.
     *
     * Un sensor de 300 ranuras que numera desde 0 llega hasta la 299; uno que
     * numerara desde 1 llegaría hasta la 300. En los dos casos son 300 ranuras, y
     * ese es justo el punto: la cuenta sale de la constante, no de suponer dónde
     * empieza.
     */
    public static function ranuraMaxima(int $capacidad): int
    {
        return self::RANURA_MINIMA + $capacidad - 1;
    }

    /** ¿Esta ranura existe en un sensor de esta capacidad? */
    public static function dentroDelRango(int $ranura, int $capacidad): bool
    {
        return $ranura >= self::RANURA_MINIMA && $ranura <= self::ranuraMaxima($capacidad);
    }

    /**
     * La menor ranura libre. «Libre» = ni asignada, ni reservada, ni ocupada
     * físicamente.
     *
     * @throws EnrolamientoImposibleException si el lector no sincronizó o está lleno
     */
    public function siguienteLibre(AsistenciaDispositivo $lector): int
    {
        if (! $lector->tieneIndiceSincronizado()) {
            throw EnrolamientoImposibleException::sinSincronizar($lector);
        }

        $ocupadas = $this->ocupadas($lector);
        $capacidad = (int) $lector->capacidad_sensor;

        for ($ranura = self::RANURA_MINIMA; $ranura <= self::ranuraMaxima($capacidad); $ranura++) {
            if (! in_array($ranura, $ocupadas, true)) {
                return $ranura;
            }
        }

        throw EnrolamientoImposibleException::sensorLleno($lector);
    }

    /**
     * TODO lo que no se puede tocar en este lector, de las tres fuentes juntas.
     *
     * @return array<int, int> ordenado, sin repetidos
     */
    public function ocupadas(AsistenciaDispositivo $lector): array
    {
        $todas = array_merge(
            $this->asignadas($lector),
            $this->reservadas($lector),
            $lector->ranurasOcupadasEnSensor(),
        );

        $unicas = array_values(array_unique(array_map('intval', $todas)));
        sort($unicas);

        return $unicas;
    }

    /** Ranuras con una asignación VIGENTE. Las históricas no estorban. */
    public function asignadas(AsistenciaDispositivo $lector): array
    {
        return AsistenciaHuella::query()
            ->where('asistencia_dispositivo_id', $lector->id)
            ->where('activo', true)
            ->pluck('fingerprint_id')
            ->all();
    }

    /** Ranuras apartadas por órdenes que siguen vivas (y sin vencer). */
    public function reservadas(AsistenciaDispositivo $lector): array
    {
        return AsistenciaOrdenEnrolamiento::query()
            ->deDispositivo($lector->id)
            ->vivas()
            ->pluck('ranura_reservada')
            ->all();
    }

    /**
     * ¿Se puede usar esta ranura concreta? Lo pregunta el escape manual de
     * «opciones avanzadas», que elige el número pero NO se salta las protecciones.
     *
     * @return string|null motivo por el que no, o null si se puede
     */
    public function motivoParaNoUsar(AsistenciaDispositivo $lector, int $ranura): ?string
    {
        if ($ranura < self::RANURA_MINIMA) {
            return 'La ranura no puede ser menor que '.self::RANURA_MINIMA.'.';
        }

        // La capacidad solo se comprueba si el lector la reportó. Sin índice, el
        // escape manual sigue disponible —es justo el camino de recuperación para
        // un sensor sin sincronizar— pero sin poder validar el tope.
        if ($lector->capacidad_sensor !== null && ! self::dentroDelRango($ranura, $lector->capacidad_sensor)) {
            return "El sensor tiene {$lector->capacidad_sensor} ranuras (de "
                .self::RANURA_MINIMA.' a '.self::ranuraMaxima($lector->capacidad_sensor).').';
        }

        if (in_array($ranura, $this->asignadas($lector), true)) {
            return "La ranura {$ranura} ya está asignada a alguien. Liberala primero.";
        }

        if (in_array($ranura, $this->reservadas($lector), true)) {
            return "La ranura {$ranura} está apartada por otro registro en curso.";
        }

        if (in_array($ranura, $lector->ranurasOcupadasEnSensor(), true)) {
            return "El sensor ya tiene una plantilla grabada en la ranura {$ranura}. "
                .'Borrala en el sensor antes de reutilizarla.';
        }

        return null;
    }
}
