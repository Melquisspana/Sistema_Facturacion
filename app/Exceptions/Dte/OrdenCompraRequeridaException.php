<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando el cliente exige número de orden de compra (requiere_orden_compra)
 * en un CCF y el borrador no lo trae.
 */
class OrdenCompraRequeridaException extends RuntimeException
{
}
