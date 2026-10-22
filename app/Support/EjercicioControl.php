<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * El EJERCICIO que, junto con el número de control, identifica un documento (#121).
 *
 * Desde 2027 el correlativo vuelve a 1 cada año (#42), así que el número solo no alcanza.
 * Antes de 2027 sí alcanzaba: por eso todo lo anterior al reinicio comparte el valor 2026 y
 * un histórico sin fecha no necesita que se le adivine el año. Es la regla que rellenó la
 * migración 2026_10_21_120000 y la que siguen Cobros y PPQ al cruzar documentos.
 */
final class EjercicioControl
{
    public const PRIMERO_CON_REINICIO = 2027;

    public const ANTERIOR_AL_REINICIO = 2026;

    /** max(año, 2026), o `null` si no hay fecha. */
    public static function de(CarbonInterface|DateTimeInterface|string|int|null $fecha): ?int
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        $anio = is_int($fecha) ? $fecha : (int) ($fecha instanceof DateTimeInterface ? $fecha->format('Y') : Carbon::parse($fecha)->year);

        return max($anio, self::ANTERIOR_AL_REINICIO);
    }

    /** El ejercicio de hoy, para lo que se registra sin fecha. */
    public static function hoy(): int
    {
        return self::de(HoraNegocio::ahora());
    }

    /** «ejercicio|NÚMERO NORMALIZADO», para cruzar en memoria; `null` sin número. */
    public static function llave(?string $numero, ?int $ejercicio): ?string
    {
        $normalizado = IdentidadPpq::normalizar($numero);

        return $normalizado === null ? null : "{$ejercicio}|{$normalizado}";
    }

    /** Acota una fecha de emisión al ejercicio, para tablas que no guardan el ejercicio. */
    public static function filtrarFecha(Builder $q, string $columna, int $ejercicio): Builder
    {
        return $ejercicio <= self::ANTERIOR_AL_REINICIO
            ? $q->whereDate($columna, '<', self::PRIMERO_CON_REINICIO.'-01-01')
            : $q->whereDate($columna, '>=', "$ejercicio-01-01")->whereDate($columna, '<=', "$ejercicio-12-31");
    }
}
