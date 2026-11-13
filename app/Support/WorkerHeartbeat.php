<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        MemoDePedido::actual()->olvidar(self::CACHE_KEY);
    }

    /**
     * Estado del worker según el último pulso.
     *
     * @return array{estado: string, ultimo: ?Carbon, hace: ?string, umbral_seg: int}
     *                                                                                estado ∈ {'activo', 'inactivo', 'sin_datos'}
     */
    public static function estado(): array
    {
        $ts = MemoDePedido::actual()->recordar(self::CACHE_KEY, fn () => Cache::get(self::CACHE_KEY));

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
        MemoDePedido::actual()->olvidar(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Diagnóstico de 4 estados: combina el heartbeat con `jobs`/`failed_jobs` para NO
     * depender solamente de "la cola está vacía" ni decir "apagado" cuando no hay forma
     * confiable de comprobarlo. Reglas (en orden):
     *  - `jobs_fallidos > 0` → SIEMPRE `critico` (hay evidencia real de un problema),
     *    sin importar el resto.
     *  - heartbeat `activo` → `correcto` (con o sin trabajos pendientes).
     *  - `inactivo` + pendientes > 0 → `critico` (el worker parece haberse detenido
     *    justo con trabajo esperando).
     *  - `inactivo` + cola vacía → `advertencia` (no urgente: no hay nada esperando).
     *  - `sin_datos` + pendientes > 0 → `critico` (nadie confirma que se estén
     *    procesando esos trabajos).
     *  - `sin_datos` + cola vacía → `advertencia`, nunca "apagado" ni verde falso: se
     *    explicita que no hay manera confiable de confirmarlo todavía.
     *
     * @return array{estado: string, ultimo: ?Carbon, hace: ?string, jobs_pendientes: int, jobs_fallidos: int, nivel: string, mensaje: string}
     */
    public static function diagnostico(): array
    {
        $hb = self::estado();
        $pendientes = (int) DB::table('jobs')->count();
        $fallidos = (int) DB::table('failed_jobs')->count();

        [$nivel, $mensaje] = match (true) {
            $fallidos > 0 => ['critico', "Hay {$fallidos} trabajo(s) fallido(s) en failed_jobs: revisar antes de continuar."],
            $hb['estado'] === 'activo' => ['correcto', 'Worker activo — último pulso '.$hb['hace'].'.'],
            $hb['estado'] === 'inactivo' && $pendientes > 0 => ['critico', 'El worker parece detenido y hay '.$pendientes.' trabajo(s) esperando en la cola.'],
            $hb['estado'] === 'inactivo' => ['advertencia', 'El worker parece detenido (último pulso '.$hb['hace'].'), pero la cola está vacía: no es urgente.'],
            $hb['estado'] === 'sin_datos' && $pendientes > 0 => ['critico', 'No hay heartbeat del worker y hay '.$pendientes.' trabajo(s) esperando: verificar que esté corriendo.'],
            default => ['advertencia', 'Sin datos de actividad reciente del worker; la cola está vacía. No hay una forma confiable de confirmar si está corriendo o no todavía.'],
        };

        return [
            'estado' => $hb['estado'],
            'ultimo' => $hb['ultimo'],
            'hace' => $hb['hace'],
            'jobs_pendientes' => $pendientes,
            'jobs_fallidos' => $fallidos,
            'nivel' => $nivel,
            'mensaje' => $mensaje,
        ];
    }
}
