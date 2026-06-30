<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza al intentar modificar o eliminar un DTE que ya no está en borrador.
 */
class DocumentoInmutableException extends RuntimeException
{
}
