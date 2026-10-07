<?php

namespace App\Services\Asistencia;

use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;

/**
 * La HORA OFICIAL del módulo, en un solo lugar.
 *
 * Existe para que «qué hora es» tenga UNA respuesta. El ping y la marcación la
 * muestran, el servicio de marcación la escribe y los reportes futuros la van a
 * comparar: si cada uno llamara a `now()` con su propia zona, bastaría un
 * descuido para que la pantalla dijera una hora y la base guardara otra.
 *
 * Dos tiempos, un instante:
 *  - {@see ahora()} devuelve el instante en la zona OFICIAL, para mostrar.
 *  - {@see instante()} lo devuelve en la zona de GUARDADO (app.timezone).
 * Son el mismo momento visto de dos formas, nunca dos lecturas distintas del
 * reloj.
 *
 * El reloj del ESP32 no entra acá jamás.
 */
class HoraOficial
{
    public function zona(): string
    {
        $zona = config('asistencia.zona_horaria');

        return filled($zona) ? (string) $zona : HoraNegocio::zona();
    }

    /** Ahora, en la zona oficial (para mostrar en pantalla). */
    public function ahora(): Carbon
    {
        return Carbon::now($this->zona());
    }

    /**
     * El MISMO instante en la zona de guardado (app.timezone), que es como se escribe en
     * la base. Eloquent no convierte zonas al guardar: un Carbon en UTC con la aplicación
     * en hora de El Salvador (producción) se escribía con la hora UTC y se leía seis horas
     * corrido (issue #59).
     */
    public function instante(): Carbon
    {
        return Carbon::now((string) config('app.timezone'));
    }

    /**
     * Un instante desglosado para el dispositivo. `epoch` va aparte porque es lo
     * único que el firmware puede usar para poner su propio reloj en hora entre
     * una marcación y otra.
     *
     * @return array<string, string|int>
     */
    public function desglosar(Carbon $instante): array
    {
        $local = $instante->copy()->setTimezone($this->zona());

        return [
            'fecha' => $local->format('Y-m-d'),
            'hora' => $local->format('H:i:s'),
            'fecha_hora' => $local->format('Y-m-d H:i:s'),
            'iso8601' => $local->toIso8601String(),
            'epoch' => $local->getTimestamp(),
            'zona' => $this->zona(),
        ];
    }

    /** El día LOCAL al que pertenece un instante (Y-m-d). */
    public function fechaLocal(Carbon $instante): string
    {
        return $instante->copy()->setTimezone($this->zona())->format('Y-m-d');
    }
}
