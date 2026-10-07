<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * El reloj del negocio en un solo lugar (decisión 0004).
 *
 * El día local decide qué significa «hoy». Producción guarda en hora de El
 * Salvador (app.timezone) y por defecto desarrollo y CI también (issue #59).
 * Convertir un instante nunca modifica el original ni cambia app.timezone.
 */
final class HoraNegocio
{
    public static function zona(): string
    {
        $zona = config('app.zona_negocio');

        return filled($zona) ? (string) $zona : 'America/El_Salvador';
    }

    public static function ahora(): Carbon
    {
        return Carbon::now(self::zona());
    }

    public static function hoy(): Carbon
    {
        return self::ahora()->startOfDay();
    }

    public static function fechaHoy(): string
    {
        return self::ahora()->format('Y-m-d');
    }

    public static function aLocal(CarbonInterface $instante): Carbon
    {
        return Carbon::instance($instante)->setTimezone(self::zona());
    }

    /**
     * Inicio de la fecha local, expresado como instante UTC. Solo para comparar
     * instantes en memoria: NO usarlo como parámetro de una consulta. Laravel lo
     * escribiría con su hora UTC sin convertirlo a app.timezone (issue #59).
     */
    public static function inicioDelDiaUtc(CarbonInterface|string $fecha): Carbon
    {
        return self::fechaLocal($fecha)->startOfDay()->setTimezone('UTC');
    }

    /** Último segundo de la fecha local, en UTC. Mismo límite: no usarlo contra la BD. */
    public static function finDelDiaUtc(CarbonInterface|string $fecha): Carbon
    {
        return self::fechaLocal($fecha)->endOfDay()->setMicrosecond(0)->setTimezone('UTC');
    }

    private static function fechaLocal(CarbonInterface|string $fecha): Carbon
    {
        return $fecha instanceof CarbonInterface
            ? self::aLocal($fecha)
            : Carbon::parse($fecha, self::zona());
    }
}
