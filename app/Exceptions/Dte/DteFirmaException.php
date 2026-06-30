<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Error de PRECONDICIÓN al firmar el JSON oficial de un DTE (no está generado,
 * no tiene JSON generado, el archivo no existe, ya está firmado, etc.).
 * No es un error de Hacienda ni del firmador: se detecta antes de firmar.
 */
class DteFirmaException extends RuntimeException
{
}
