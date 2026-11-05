<?php

namespace App\Services\Alertas\Detectores;

use App\Facades\Ajustes;
use App\Models\RespaldoEjecucion;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Support\HoraNegocio;

/**
 * Respaldo diario de la base (`backup:mysql-diario`, registrado en
 * `respaldo_ejecuciones`): el último falló, o a la hora límite no hay uno válido
 * de hoy. Los respaldos de spatie (`backup:run`) avisan como tareas programadas.
 */
class RespaldoFallido implements Detector
{
    public function tipo(): string
    {
        return 'respaldo_fallido';
    }

    public function nombre(): string
    {
        return 'Respaldo de la base de datos';
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        $queHacer = 'Corré «php artisan backup:mysql-diario --origen=manual» en el servidor y revisá que la tarea programada del respaldo esté activa.';
        $ultimo = RespaldoEjecucion::query()->latest('iniciado_en')->latest('id')->first();

        if ($ultimo !== null && ! $ultimo->exitoso) {
            return new Hallazgo(
                titulo: 'El respaldo de la base de datos falló',
                detalle: 'El último respaldo registrado terminó con error.',
                queHacer: $queHacer,
                desde: $ultimo->terminado_en ?? $ultimo->iniciado_en,
                enlace: 'admin.salud-sistema',
            );
        }

        $limite = HoraNegocio::hoy()->setTimeFromTimeString((string) Ajustes::texto('alertas.respaldo_hora_limite', '06:00'));

        if (HoraNegocio::ahora()->gte($limite) && ! RespaldoEjecucion::hayValidoHoy()) {
            return new Hallazgo(
                titulo: 'No hay respaldo de la base de hoy',
                detalle: 'A las '.$limite->format('H:i').' todavía no había un respaldo válido de hoy.',
                queHacer: $queHacer,
                desde: $limite,
                enlace: 'admin.salud-sistema',
            );
        }

        return null;
    }
}
