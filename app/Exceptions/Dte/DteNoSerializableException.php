<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando un DTE no puede serializarse a la estructura oficial del MH
 * porque faltan mapeos imprescindibles (p. ej. unidad de medida CAT-014 inválida
 * o tipo de ítem CAT-011 ausente). No se inventan valores.
 */
class DteNoSerializableException extends RuntimeException
{
    /**
     * @param  array<int, string>  $problemas
     */
    public function __construct(public readonly array $problemas)
    {
        parent::__construct('El DTE no se puede serializar a JSON oficial: '.implode(' ', $problemas));
    }
}
