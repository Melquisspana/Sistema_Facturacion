<?php

namespace App\DataTransferObjects\Dte;

use App\Enums\TipoImpuesto;

/**
 * Entrada de una línea para la CalculadoraDte. Independiente de la BD: la
 * calculadora no sabe de Eloquent.
 *
 * Los importes se manejan como cadenas decimales (estrategia de dinero exacto).
 */
final readonly class LineaDocumento
{
    public function __construct(
        public string $cantidad,
        public string $precioUnitario,
        public TipoImpuesto $tipoImpuesto,
        public string $descuentoMonto = '0',
        public ?string $descripcion = null,
    ) {}

    /** Atajo para una línea gravada. */
    public static function gravado(string|int|float $cantidad, string|int|float $precio, string|int|float $descuento = 0): self
    {
        return new self((string) $cantidad, (string) $precio, TipoImpuesto::Gravado, (string) $descuento);
    }

    /** Atajo para una línea exenta. */
    public static function exento(string|int|float $cantidad, string|int|float $precio, string|int|float $descuento = 0): self
    {
        return new self((string) $cantidad, (string) $precio, TipoImpuesto::Exento, (string) $descuento);
    }

    /** Atajo para una línea no sujeta. */
    public static function noSujeto(string|int|float $cantidad, string|int|float $precio, string|int|float $descuento = 0): self
    {
        return new self((string) $cantidad, (string) $precio, TipoImpuesto::NoSujeto, (string) $descuento);
    }
}
