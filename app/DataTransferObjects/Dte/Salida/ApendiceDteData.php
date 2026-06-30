<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Un ítem del apéndice del DTE (estructura interna). Aquí va, por ejemplo, el
 * número de orden de compra para clientes/sucursales que lo requieren.
 */
final readonly class ApendiceDteData
{
    public function __construct(
        public string $campo,
        public string $etiqueta,
        public string $valor,
    ) {}

    /** Atajo para el número de orden de compra. */
    public static function ordenCompra(string $valor, string $etiqueta = 'Orden de compra'): self
    {
        return new self(campo: 'ordenCompra', etiqueta: $etiqueta, valor: $valor);
    }
}
