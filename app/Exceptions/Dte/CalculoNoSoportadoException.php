<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando la CalculadoraDte recibe un caso que todavía no está
 * implementado (se irá habilitando por sub-pasos: exento, no sujeto, factura,
 * exportación, descuentos, retención).
 */
class CalculoNoSoportadoException extends RuntimeException
{
}
