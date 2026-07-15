<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Sección "resumen" (totales) del DTE (estructura interna). Importes como cadenas
 * decimales. `totalLetras` puede provenir de App\Support\Dte\NumeroALetras.
 */
final readonly class ResumenDteData
{
    public function __construct(
        public string $totalGravado,
        public string $totalExento,
        public string $totalNoSujeto,
        public string $totalExportacion,
        public string $descuentoGravado,
        public string $descuentoExento,
        public string $descuentoNoSujeto,
        public string $descuentoTotal,
        public string $iva,
        public string $ivaRetenido,
        public string $retencionRenta,
        public string $totalAntesRetencion,
        public string $montoTotalOperacion,
        public string $totalPagar,
        public ?string $totalLetras = null,
        public string $flete = '0.00',
        public string $seguro = '0.00',
        public ?int $condicionOperacion = null,
        public string $porcentajeDescuento = '0.00',
        public ?string $formaPago = null,
        // Factura de exportación (11) únicamente; por-DTE. El resto de tipos deja estos
        // campos en null y sus serializadores no los leen.
        public ?string $codIncoterms = null,
        public ?string $descIncoterms = null,
    ) {}
}
