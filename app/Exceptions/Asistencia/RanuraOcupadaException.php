<?php

namespace App\Exceptions\Asistencia;

use App\Models\Asistencia\AsistenciaHuella;
use RuntimeException;

/**
 * Se intentó asignar una ranura que ya tiene una asignación VIGENTE.
 *
 * Lleva la asignación que estorba —no solo el número— porque quien tenga que
 * resolverlo necesita saber de QUIÉN es ahora esa ranura, y volver a consultarlo
 * desde la pantalla sería repetir la consulta que este servicio acaba de hacer.
 *
 * No es un error de programación: es el estado normal del sistema cuando alguien
 * se equivoca de número. Por eso es una excepción de dominio con un mensaje
 * legible, y no una violación de restricción de la base —aunque la base también
 * lo impida, que es lo que hace que esta comprobación no sea la única defensa.
 */
class RanuraOcupadaException extends RuntimeException
{
    private function __construct(
        string $mensaje,
        public readonly AsistenciaHuella $ocupante,
    ) {
        parent::__construct($mensaje);
    }

    public static function para(AsistenciaHuella $ocupante): self
    {
        $empleado = $ocupante->empleado?->nombreCompleto() ?? 'un empleado dado de baja';
        $lector = $ocupante->dispositivo?->codigo ?? 'desconocido';

        return new self(
            "La ranura {$ocupante->fingerprint_id} del lector «{$lector}» ya está asignada a {$empleado}. "
            .'Liberala primero (y borrá la plantilla en el sensor) para poder reutilizarla.',
            $ocupante,
        );
    }
}
