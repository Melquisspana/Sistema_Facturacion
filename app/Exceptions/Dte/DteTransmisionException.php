<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Error de PRECONDICIÓN al transmitir un DTE (no está generado, no tiene JWS
 * firmado, ya tiene sello, ya está aceptado/invalidado, etc.). Se detecta antes
 * de cualquier llamada a Hacienda.
 */
class DteTransmisionException extends RuntimeException
{
}
