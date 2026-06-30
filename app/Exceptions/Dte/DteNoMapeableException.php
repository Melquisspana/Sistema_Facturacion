<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando un DTE no puede mapearse a la estructura de salida porque la
 * validación previa (ValidacionPreJsonService) encontró problemas.
 */
class DteNoMapeableException extends RuntimeException
{
    /**
     * @param  array<int, string>  $problemas
     */
    public function __construct(public readonly array $problemas)
    {
        parent::__construct('El DTE no está listo para mapear: '.implode(' ', $problemas));
    }
}
