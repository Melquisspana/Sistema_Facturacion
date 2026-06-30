<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando un documento no puede anularse internamente (no está generado,
 * ya está anulado, etc.).
 */
class AnulacionException extends RuntimeException
{
}
