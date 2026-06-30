<?php

namespace App\Exceptions\Dte;

/**
 * La firma está DESHABILITADA o todavía no implementada (fase de preparación).
 * Se lanza DESPUÉS de validar las precondiciones, como "parada segura": el
 * sistema confirma que el DTE está listo para firmar pero NO ejecuta la firma
 * real porque la integración con el firmador todavía no está habilitada.
 */
class DteFirmaDeshabilitadaException extends DteFirmaException
{
}
