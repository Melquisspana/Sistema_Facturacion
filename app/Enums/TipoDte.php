<?php

namespace App\Enums;

/**
 * Tipo de Documento Tributario Electrónico (DTE).
 * Códigos según catálogo CAT-002 del Ministerio de Hacienda de El Salvador.
 *
 * En Fase 1 solo se define la estructura. La emisión real llega en fases posteriores.
 */
enum TipoDte: string
{
    case Factura = '01';            // Factura (consumidor final)
    case CreditoFiscal = '03';      // Comprobante de Crédito Fiscal (CCF)
    case NotaCredito = '05';        // Nota de Crédito
    case NotaDebito = '06';         // Nota de Débito
    case FacturaExportacion = '11'; // Factura de Exportación

    /** Nombre legible para la interfaz. */
    public function label(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::CreditoFiscal => 'Comprobante de Crédito Fiscal',
            self::NotaCredito => 'Nota de Crédito',
            self::NotaDebito => 'Nota de Débito',
            self::FacturaExportacion => 'Factura de Exportación',
        };
    }

    /** Versión del esquema JSON del MH para este tipo de documento. */
    public function versionEsquema(): int
    {
        return match ($this) {
            self::Factura => 1,
            self::CreditoFiscal => 3,
            self::NotaCredito => 3,
            self::NotaDebito => 3,
            self::FacturaExportacion => 1,
        };
    }

    /**
     * Tipos habilitados para el alcance del proyecto (Fases 2-4).
     *
     * @return array<int, self>
     */
    public static function habilitados(): array
    {
        return [
            self::Factura,
            self::CreditoFiscal,
            self::NotaCredito,
            self::FacturaExportacion,
        ];
    }
}
