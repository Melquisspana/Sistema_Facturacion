<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Error de PRECONDICIÓN al generar el JSON oficial preliminar de un DTE
 * (no es CCF, no está generado, ya tiene JSON, etc.). No es de Hacienda.
 */
class DteJsonException extends RuntimeException
{
}
