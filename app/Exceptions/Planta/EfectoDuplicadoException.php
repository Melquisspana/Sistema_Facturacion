<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El mismo efecto exacto ya está aplicado en el mayor.
 *
 * La detecta el UNIQUE sobre `efecto_uid`, no una consulta previa: entre un
 * `SELECT ... WHERE efecto_uid = ?` y el `INSERT` cabe otro proceso, y el hueco
 * es justo lo que un reintento o un doble clic aprovecha. El servicio intenta
 * insertar y traduce la violación del motor.
 *
 * Que se lance NO significa que el saldo esté mal: significa que ese efecto ya
 * estaba contado y no debe contarse otra vez.
 */
class EfectoDuplicadoException extends RuntimeException
{
    public function __construct(public readonly string $efectoUid, string $mensaje)
    {
        parent::__construct($mensaje);
    }

    public static function crear(string $efectoUid, string $descripcion): self
    {
        return new self($efectoUid, sprintf(
            'El efecto %s (%s) ya está registrado en el mayor: aplicarlo otra vez duplicaría el saldo.',
            substr($efectoUid, 0, 12).'…',
            $descripcion,
        ));
    }
}
