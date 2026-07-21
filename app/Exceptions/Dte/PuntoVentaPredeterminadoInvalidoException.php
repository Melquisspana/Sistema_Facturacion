<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando hay un punto de venta predeterminado configurado
 * (dte.punto_venta_predeterminado / DTE_PUNTO_VENTA_PREDETERMINADO) pero no es
 * válido: el código no existe, está inactivo, o no pertenece al establecimiento
 * resuelto. Nunca se cae de vuelta a otro punto de venta en silencio.
 */
class PuntoVentaPredeterminadoInvalidoException extends RuntimeException
{
}
