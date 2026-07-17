<?php

namespace App\Exceptions\Exportaciones;

use RuntimeException;

/**
 * Se lanza al intentar crear una FEX para una Lista de Empaque que ya tiene
 * una Factura de Exportación vinculada (exportaciones.dte_id no nulo).
 */
class FexYaExisteException extends RuntimeException
{
    public function __construct(public readonly int $dteId)
    {
        parent::__construct("La lista ya tiene una Factura de exportación vinculada (DTE #{$dteId}).");
    }
}
