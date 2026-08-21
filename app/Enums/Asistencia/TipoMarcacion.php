<?php

namespace App\Enums\Asistencia;

/**
 * Qué representa una marcación. Hoy solo dos casos, y la regla que los alterna
 * vive en {@see siguienteTras()}: es la ÚNICA fuente de verdad de si el próximo
 * dedo es entrada o salida.
 *
 *   (nada hoy) --> entrada --> salida --> entrada --> ...
 *
 * Se alterna dentro del DÍA LOCAL, no se cuenta «la primera y la segunda»: así
 * quien entra, sale a almorzar y vuelve produce entrada/salida/entrada/salida sin
 * que el módulo tenga que saber todavía qué es un almuerzo. Cuando existan los
 * horarios, la clasificación fina (almuerzo, permiso, hora extra) se decide con
 * el horario del empleado y se agrega acá como casos nuevos; el histórico ya
 * escrito no se invalida, porque entrada y salida siguen significando lo mismo.
 */
enum TipoMarcacion: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';

    public function label(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::Salida => 'Salida',
        };
    }

    /**
     * Qué corresponde marcar DESPUÉS de `$anterior`. Sin marcación previa en el
     * día, entrada: nadie sale de un lugar al que no entró.
     */
    public static function siguienteTras(?self $anterior): self
    {
        return match ($anterior) {
            null, self::Salida => self::Entrada,
            self::Entrada => self::Salida,
        };
    }
}
