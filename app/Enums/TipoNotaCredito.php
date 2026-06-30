<?php

namespace App\Enums;

/**
 * Modalidad INTERNA de una nota de crédito. No es un catálogo oficial del MH:
 * clasifica el flujo (por productos vs. por monto/concepto) y sus reglas internas.
 * La representación final en el JSON del MH queda pendiente de validar.
 */
enum TipoNotaCredito: string
{
    case DevolucionProducto = 'devolucion_producto';
    case FaltanteEntrega = 'faltante_entrega';
    case Averia = 'averia';
    case ProntoPago = 'pronto_pago';
    case DescuentoPosterior = 'descuento_posterior';
    case AjusteComercial = 'ajuste_comercial';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::DevolucionProducto => 'Devolución de productos',
            self::FaltanteEntrega => 'Faltante de entrega',
            self::Averia => 'Avería',
            self::ProntoPago => 'Pronto pago',
            self::DescuentoPosterior => 'Descuento posterior',
            self::AjusteComercial => 'Ajuste comercial',
            self::Otro => 'Otro',
        };
    }

    /**
     * ¿Acredita líneas/cantidades del CCF ORIGINAL? Solo devolución y faltante:
     * estas SOLO permiten productos del CCF relacionado (con saldo disponible).
     */
    public function esPorProductos(): bool
    {
        return in_array($this, [self::DevolucionProducto, self::FaltanteEntrega], true);
    }

    /**
     * Avería: acredita PRODUCTOS LIBRES del catálogo (cualquier producto activo),
     * sin limitarse a las líneas del CCF original ni validar su saldo.
     */
    public function esPorAveria(): bool
    {
        return $this === self::Averia;
    }

    /** ¿Es por monto/concepto (líneas manuales, sin producto)? */
    public function esPorMonto(): bool
    {
        return ! $this->esPorProductos() && ! $this->esPorAveria();
    }

    /** Solo las NC que acreditan líneas del original (devolución/faltante) lo exigen. */
    public function requiereDocumentoRelacionado(): bool
    {
        return $this->esPorProductos();
    }

    /** @return array<string, string> [valor => label] para selects. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
