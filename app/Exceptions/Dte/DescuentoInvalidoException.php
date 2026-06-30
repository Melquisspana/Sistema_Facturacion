<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando el descuento global es negativo o mayor al subtotal disponible.
 */
class DescuentoInvalidoException extends RuntimeException
{
}
