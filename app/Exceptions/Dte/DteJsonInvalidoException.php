<?php

namespace App\Exceptions\Dte;

/**
 * El JSON generado NO pasó la validación contra el JSON Schema oficial del MH.
 * Se lanza dentro de la transacción para que NO quede numeración ni path a medias.
 */
class DteJsonInvalidoException extends DteJsonException
{
    /**
     * @param  array<int, string>  $errores
     */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('El JSON no es válido contra el schema oficial: '.implode(' | ', $errores));
    }
}
