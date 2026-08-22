<?php

namespace App\Enums\Asistencia;

/**
 * Cómo quedó la secuencia de marcaciones de una persona en un día local.
 *
 * ─────────────────── Describe, no juzga ───────────────────
 *
 * Estos estados hablan SOLO de la forma de los datos: si cada entrada tiene su
 * salida. No dicen si alguien llegó tarde, si trabajó poco o si faltó — eso
 * exigiría una hora oficial de entrada, una jornada esperada y un calendario
 * laboral, y **ninguna de las tres existe en el sistema**. Un estado que dijera
 * «Tardanza» sería una regla inventada, y en una pantalla de asistencia una regla
 * inventada se convierte en una discusión de planilla.
 *
 * Por eso no hay `Puntual`, `Ausente`, `Incompleta` ni `Extra`: todos ellos
 * presuponen algo que nadie declaró todavía.
 */
enum EstadoJornada: string
{
    /** Cada entrada tiene su salida. El tiempo trabajado es exacto. */
    case Completa = 'completa';

    /**
     * La secuencia alterna bien pero termina con una entrada sin cerrar. Es el
     * caso de quien olvida marcar la salida —y también el del turno que cruza la
     * medianoche, que hoy el sistema no sabe cerrar (ver `Jornada`)—.
     *
     * El tiempo de los tramos ya cerrados sigue siendo exacto; el total es un
     * MÍNIMO, no la jornada completa.
     */
    case Abierta = 'abierta';

    /**
     * Hay marcaciones que no encajan en la alternancia: una salida sin entrada
     * previa, o dos entradas seguidas.
     *
     * Vía dispositivo esto NO puede ocurrir —la regla de alternancia lo impide y
     * el día siempre empieza en entrada—, así que solo llega de una corrección
     * manual o de datos cargados por otra vía. Se distingue de `Abierta` porque
     * significa algo distinto: no es «falta cerrar», es «esto no cuadra».
     */
    case Irregular = 'irregular';

    public function label(): string
    {
        return match ($this) {
            self::Completa => 'Completa',
            self::Abierta => 'Abierta',
            self::Irregular => 'Irregular',
        };
    }

    /** Qué significa, en una frase, para quien mira el reporte. */
    public function explicacion(): string
    {
        return match ($this) {
            self::Completa => 'Cada entrada tiene su salida. El tiempo trabajado es exacto.',
            self::Abierta => 'Quedó una entrada sin salida. El tiempo trabajado es un mínimo, no el total.',
            self::Irregular => 'Hay marcaciones que no encajan en la secuencia entrada/salida. Revisar.',
        };
    }

    /** ¿El tiempo trabajado que se muestra es el total real, o solo una parte? */
    public function tiempoEsExacto(): bool
    {
        return $this === self::Completa;
    }

    /** ¿Pide que alguien la mire? */
    public function requiereAtencion(): bool
    {
        return $this !== self::Completa;
    }

    /** Clases del badge, en el vocabulario de color del resto del sistema. */
    public function clases(): string
    {
        return match ($this) {
            self::Completa => 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
            self::Abierta => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            self::Irregular => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        };
    }
}
