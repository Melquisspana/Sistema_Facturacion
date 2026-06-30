<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza al intentar una transición de estado no permitida por la máquina de
 * estados del DTE.
 */
class TransicionInvalidaException extends RuntimeException
{
}
