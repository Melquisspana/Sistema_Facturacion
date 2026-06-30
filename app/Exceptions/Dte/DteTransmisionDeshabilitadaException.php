<?php

namespace App\Exceptions\Dte;

/**
 * La transmisión está DESHABILITADA (fase de preparación). Se lanza como "parada
 * segura": el documento puede estar listo, pero NO se envía nada a Hacienda
 * mientras 'dte.transmision.enabled' = false.
 */
class DteTransmisionDeshabilitadaException extends DteTransmisionException
{
}
