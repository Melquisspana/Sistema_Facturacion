<?php

namespace App\Enums\Asistencia;

/**
 * En qué punto está una orden de enrolamiento.
 *
 *   pendiente ─► tomada ─► en_curso ─► completada   (nace la asistencia_huella)
 *       │          │          │
 *       └──────────┴──────────┴─────► fallida | expirada | cancelada
 *
 * Los tres primeros son estados VIVOS: la orden ocupa el buzón del lector y tiene
 * una ranura apartada. Los cuatro últimos son finales y liberan las dos cosas.
 *
 * La distinción no es decorativa: las dos unicidades parciales de la tabla se
 * construyen sobre exactamente esta lista ({@see self::VIVOS}), así que agregar un
 * estado obliga a decidir de qué lado cae.
 */
enum EstadoOrdenEnrolamiento: string
{
    /** Creada, esperando que el lector la recoja en su próximo sondeo. */
    case Pendiente = 'pendiente';

    /** El lector la recogió y tiene su token. Todavía no puso nadie el dedo. */
    case Tomada = 'tomada';

    /** El AS608 está capturando. El lector va reportando etapas. */
    case EnCurso = 'en_curso';

    /** El sensor confirmó que grabó la plantilla y ya existe la asignación. */
    case Completada = 'completada';

    /** Algo salió mal. `motivo_fallo` dice qué, con un código estable. */
    case Fallida = 'fallida';

    /** Se agotó su tiempo. NO revive: una orden vencida no se ejecuta nunca. */
    case Expirada = 'expirada';

    /** La canceló una persona desde la web, o el propio lector. */
    case Cancelada = 'cancelada';

    /**
     * Los estados en los que la orden ocupa el buzón y aparta su ranura. Es la
     * MISMA lista que usan las columnas generadas de la migración; si divergieran,
     * la base y la aplicación discreparían sobre qué está vivo.
     */
    public const VIVOS = ['pendiente', 'tomada', 'en_curso'];

    /** @return array<int, string> */
    public static function vivos(): array
    {
        return self::VIVOS;
    }

    public function estaViva(): bool
    {
        return in_array($this->value, self::VIVOS, true);
    }

    public function esFinal(): bool
    {
        return ! $this->estaViva();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Esperando al lector',
            self::Tomada => 'Lector listo',
            self::EnCurso => 'Capturando huella',
            self::Completada => 'Registrada',
            self::Fallida => 'Falló',
            self::Expirada => 'Expiró',
            self::Cancelada => 'Cancelada',
        };
    }

    /** Qué tiene que hacer o esperar la persona que está frente al lector. */
    public function explicacion(): string
    {
        return match ($this) {
            self::Pendiente => 'La orden ya está en el buzón. El lector la recoge en unos segundos.',
            self::Tomada => 'El lector recibió la orden. Pedile a la persona que coloque el dedo.',
            self::EnCurso => 'El sensor está capturando. Seguí las indicaciones de la pantalla del lector.',
            self::Completada => 'La huella quedó grabada en el sensor y asociada a la persona.',
            self::Fallida => 'No se pudo grabar. Podés volver a intentarlo.',
            self::Expirada => 'Pasó demasiado tiempo sin completarse. Volvé a iniciar el registro.',
            self::Cancelada => 'Se canceló antes de grabar nada.',
        };
    }

    public function clases(): string
    {
        return match ($this) {
            self::Pendiente, self::Tomada, self::EnCurso => 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
            self::Completada => 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
            self::Fallida => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
            self::Expirada, self::Cancelada => 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-400',
        };
    }
}
