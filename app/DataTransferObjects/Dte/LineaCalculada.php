<?php

namespace App\DataTransferObjects\Dte;

/**
 * Resultado del cálculo de una línea (importes ya redondeados a 2 decimales,
 * como cadenas, ej. "20.00").
 */
final readonly class LineaCalculada
{
    public function __construct(
        public string $ventaGravada,
        public string $ventaExenta,
        public string $ventaNoSujeta,
        public string $ivaLinea,
        public string $totalLinea,
        // Venta exportada (tipo 11, 0% IVA). En CCF/Factura queda en cero.
        public string $ventaExportacion = '0.00',
    ) {}
}
