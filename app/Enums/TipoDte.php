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

    /*
    | (Eliminado) versionEsquema(). Declaraba una SEGUNDA versión de esquema por tipo
    | —Factura 1, CCF 3, FEX 1— que CONTRADECÍA la autoritativa de
    | config('dte.json.versiones') —Factura 2, CCF 4, FEX 3—.
    |
    | No estaba en uso: su única llamada era config/dte.php, que la volcaba en
    | dte.tipos.*.version_esquema, y esa clave no tenía ningún consumidor (ni código,
    | ni vistas, ni accesos dinámicos, ni tests). No participaba en la generación, ni
    | en la validación contra schema, ni en el payload de recepción.
    |
    | Se eliminó porque config muerta que además CONTRADICE a la viva no es inofensiva:
    | es una trampa para quien lea el enum creyéndolo la fuente. Las versiones
    | autoritativas siguen siendo, sin cambios, las de config('dte.json.versiones'):
    | Factura 2 · CCF 4 · NC 3 · FEX 3 · invalidación 3.
    */

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
