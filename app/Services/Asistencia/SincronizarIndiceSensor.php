<?php

namespace App\Services\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;

/**
 * Guarda lo que el AS608 dice de sí mismo: cuántas ranuras tiene y cuáles están
 * realmente grabadas.
 *
 * ────────────────────── Por qué hace falta ──────────────────────
 *
 * Porque el servidor y el sensor saben cosas distintas, y solo juntas alcanzan
 * para elegir dónde grabar:
 *
 *   la BASE sabe   -> qué ranuras están asignadas y reservadas
 *   el AS608 sabe  -> qué ranuras tienen plantilla FÍSICA
 *
 * Una plantilla heredada —de antes de que existiera este sistema, o de un
 * enrolamiento hecho a mano en el sensor— es invisible para la base. Sin esta
 * sincronización, el servidor la elegiría como «libre» y el enrolamiento fallaría
 * delante del empleado, con el dedo puesto.
 *
 * ────────────────────── Es telemetría, no administración ──────────────────────
 *
 * No se audita: la escribe el lector, puede ocurrir en cada arranque y una entrada
 * de auditoría por sincronización llenaría el registro sin decir nada que no esté
 * ya en las tres columnas. Igual que la última conexión.
 *
 * La capacidad que manda es SIEMPRE la que reporta el hardware. No hay un valor
 * por defecto que la sobreviva: los AS608 varían entre modelos y una constante en
 * el código sería verdad hasta el día que se instale otro sensor.
 */
class SincronizarIndiceSensor
{
    /**
     * @param  array<int, int|string>  $ocupadas  ranuras con plantilla física
     */
    public function __invoke(AsistenciaDispositivo $lector, int $capacidad, array $ocupadas): AsistenciaDispositivo
    {
        // Se descarta lo que no cabe en el sensor que el propio lector declara: una
        // ranura fuera de rango no puede tener plantilla, y guardarla ensuciaría la
        // exclusión sin protegernos de nada.
        //
        // El rango sale de SelectorRanura y no de un `< $capacidad` escrito acá: si
        // alguna vez se instalara un sensor que numera desde 1, este filtro tiene
        // que moverse con el resto o empezaría a tirar la última ranura buena.
        $dentroDeRango = array_filter(
            array_map('intval', $ocupadas),
            fn (int $ranura) => SelectorRanura::dentroDelRango($ranura, $capacidad),
        );

        $lector->sincronizarIndice($capacidad, $dentroDeRango);

        return $lector;
    }
}
