<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Latido (heartbeat) del worker de colas. Cada iteración del daemon `queue:work`
 * dispara el evento Looping (ver AppServiceProvider), que llama a {@see pulse()} para
 * marcar en cache que el worker está vivo. El panel de administración lee {@see estado()}
 * para avisar si el worker parece detenido.
 *
 * SOLO observación: no toca la cola, ni el envío, ni la firma/transmisión. Funciona con
 * cache compartida (database/file) sin servicios externos.
 */
class WorkerHeartbeat
{
    private const CACHE_KEY = 'worker.heartbeat.ts';

    /** Se considera "activo" si el último pulso es más reciente que esto (segundos). */
    private const UMBRAL_ACTIVO_SEG = 120;

    /** El worker hace loop cada pocos segundos: no escribimos en cache más seguido que esto. */
    private const THROTTLE_SEG = 15;

    /** Último pulso escrito por ESTE proceso (el worker es de larga vida); evita escrituras por loop. */
    private static ?int $ultimoPulsoProceso = null;

    /** Marca el worker vivo (llamado desde el evento Looping). Throttled dentro del proceso. */
    public static function pulse(): void
    {
        $ahora = now()->getTimestamp();

        if (self::$ultimoPulsoProceso !== null && ($ahora - self::$ultimoPulsoProceso) < self::THROTTLE_SEG) {
            return;
        }

        self::$ultimoPulsoProceso = $ahora;
        Cache::put(self::CACHE_KEY, $ahora, now()->addDay());
    }

    /**
     * Estado del worker según el último pulso.
     *
     * @return array{estado: string, ultimo: ?Carbon, hace: ?string, umbral_seg: int}
     *   estado ∈ {'activo', 'inactivo', 'sin_datos'}
     */
    public static function estado(): array
    {
        $ts = Cache::get(self::CACHE_KEY);

        if (! $ts) {
            return ['estado' => 'sin_datos', 'ultimo' => null, 'hace' => null, 'umbral_seg' => self::UMBRAL_ACTIVO_SEG];
        }

        $ultimo = Carbon::createFromTimestamp((int) $ts);
        $activo = $ultimo->gt(now()->subSeconds(self::UMBRAL_ACTIVO_SEG));

        return [
            'estado' => $activo ? 'activo' : 'inactivo',
            'ultimo' => $ultimo,
            'hace' => $ultimo->diffForHumans(),
            'umbral_seg' => self::UMBRAL_ACTIVO_SEG,
        ];
    }

    /** Reinicia el latido (throttle en memoria + valor en cache). Útil en pruebas. */
    public static function olvidar(): void
    {
        self::$ultimoPulsoProceso = null;
        Cache::forget(self::CACHE_KEY);
    }
}
