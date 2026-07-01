<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando no se puede ejecutar el evento de invalidación oficial (mock o
 * real): la NC no está aceptada realmente por el MH, ya tiene un evento de
 * invalidación, el modo mock está apagado sin confirmación, etc.
 */
class DteInvalidacionException extends RuntimeException
{
}
