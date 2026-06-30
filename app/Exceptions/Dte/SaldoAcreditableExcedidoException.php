<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Se lanza cuando una nota de crédito intenta acreditar más cantidad/monto del
 * que queda disponible en la línea del documento original.
 */
class SaldoAcreditableExcedidoException extends RuntimeException
{
}
