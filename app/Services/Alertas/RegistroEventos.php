<?php

namespace App\Services\Alertas;

use App\Facades\Ajustes;
use Illuminate\Console\Scheduling\Event as TareaProgramada;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fallas que PASAN en un momento (un error 500, una tarea programada que termina
 * mal) y no quedan en ninguna tabla. Se anotan acá y `alertas:revisar` decide si
 * avisar.
 *
 * Anotar solo escribe en caché: nunca manda correo. Así un pico de errores no
 * dispara un correo por error, y un correo que falla no puede volver a entrar acá.
 * Tampoco lanza: se llama desde el manejo de excepciones y no puede romperlo.
 */
final class RegistroEventos
{
    public const ERRORES_500 = 'errores_500';

    public const TAREAS = 'tareas_programadas';

    /** Tareas que fallaron mientras el servidor no llegaba a internet. */
    public const SIN_CONEXION = 'sin_conexion';

    /** Etiquetas distintas que se guardan por tipo; el resto solo suma al total. */
    private const MAX_ETIQUETAS = 10;

    public static function anotar(string $tipo, string $etiqueta): void
    {
        if (! Ajustes::boolSinBase('alertas.enabled')) {
            return;
        }

        try {
            $ahora = now()->getTimestamp();
            $evento = Cache::get(self::clave($tipo)) ?? ['primera' => $ahora, 'cantidad' => 0, 'etiquetas' => []];
            $evento['ultima'] = $ahora;
            $evento['cantidad']++;

            $etiqueta = mb_substr($etiqueta, 0, 80);
            if (isset($evento['etiquetas'][$etiqueta]) || count($evento['etiquetas']) < self::MAX_ETIQUETAS) {
                $evento['etiquetas'][$etiqueta] = ($evento['etiquetas'][$etiqueta] ?? 0) + 1;
            }

            Cache::put(self::clave($tipo), $evento, now()->addDays(2));
        } catch (Throwable) {
            // Sin caché no hay dónde anotar; el error original sigue su curso.
        }
    }

    /**
     * Una tarea que terminó mal. Si en ese momento el servidor no llega a internet, va a
     * SIN_CONEXION: un corte de red hace fallar a la vez todas las tareas que lo usan y
     * se avisa una sola vez «Sin conexión a internet», no una falla por tarea.
     */
    public static function anotarTareaFallida(string $tarea): void
    {
        if (! Ajustes::boolSinBase('alertas.enabled')) {
            return;
        }

        self::anotar(self::hayInternet() ? self::TAREAS : self::SIN_CONEXION, $tarea);
    }

    /**
     * La respuesta se guarda un minuto: cuando falla una tanda de tareas, se prueba una
     * vez. Si la prueba misma falla, se toma como que hay internet y la tarea se anota
     * como siempre.
     */
    private static function hayInternet(): bool
    {
        try {
            return (bool) Cache::remember('alertas.hay_internet', 60,
                static fn () => app(ComprobadorConexion::class)->hayInternet());
        } catch (Throwable) {
            return true;
        }
    }

    /** @return array{primera: int, ultima: int, cantidad: int, etiquetas: array<string, int>}|null */
    public static function leer(string $tipo): ?array
    {
        return Cache::get(self::clave($tipo));
    }

    public static function olvidar(string $tipo): void
    {
        Cache::forget(self::clave($tipo));
    }

    /**
     * El comando artisan de la tarea, sin la ruta de PHP ni del proyecto que trae
     * `$task->command`.
     */
    public static function nombreTarea(TareaProgramada $tarea): string
    {
        if (filled($tarea->description)) {
            return (string) $tarea->description;
        }

        return preg_match('/artisan[\'"]?\s+([\w:\-]+)/', (string) $tarea->command, $m) === 1
            ? $m[1]
            : 'tarea programada';
    }

    private static function clave(string $tipo): string
    {
        return 'alertas.eventos.'.$tipo;
    }
}
