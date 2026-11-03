<?php

namespace App\Services\Alertas;

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

    /** Etiquetas distintas que se guardan por tipo; el resto solo suma al total. */
    private const MAX_ETIQUETAS = 10;

    public static function anotar(string $tipo, string $etiqueta): void
    {
        if (! config('alertas.enabled')) {
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
