<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando un DTE no puede generarse (sin líneas, sin correlativo válido,
 * falta orden de compra, no está en borrador, etc.).
 */
class GeneracionException extends RuntimeException
{
}
