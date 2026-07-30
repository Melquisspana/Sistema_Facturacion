<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El movimiento dejaría el bucket en negativo.
 *
 * El inventario de Planta NO admite saldo negativo en ningún estado: un negativo
 * no es «deuda de inventario», es un error de captura que se propaga a todo lo
 * que lea ese saldo. Se rechaza la operación completa y se deja el bucket como
 * estaba.
 */
class SaldoInsuficienteException extends RuntimeException
{
    public function __construct(
        public readonly string $saldoAntes,
        public readonly string $cantidad,
        public readonly string $saldoResultante,
        string $mensaje,
    ) {
        parent::__construct($mensaje);
    }

    public static function crear(string $saldoAntes, string $cantidad, string $resultante, string $descripcionBucket): self
    {
        return new self($saldoAntes, $cantidad, $resultante, sprintf(
            'Saldo insuficiente en %s: hay %s y el movimiento de %s lo dejaría en %s. '
            .'El inventario no admite saldo negativo.',
            $descripcionBucket,
            $saldoAntes,
            $cantidad,
            $resultante,
        ));
    }
}
