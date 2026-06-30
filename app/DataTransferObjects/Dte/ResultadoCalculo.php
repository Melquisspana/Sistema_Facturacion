<?php

namespace App\DataTransferObjects\Dte;

/**
 * Totales del documento (cadenas con 2 decimales). Estructura preparada para
 * ir sumando exento, no sujeto, descuentos y retención en los pasos siguientes.
 */
final readonly class ResultadoCalculo
{
    /**
     * @param  array<int, LineaCalculada>  $lineas
     */
    public function __construct(
        public array $lineas,
        public string $subtotal,
        public string $totalGravado,
        public string $totalExento,
        public string $totalNoSujeto,
        public string $descuentoGravado,
        public string $descuentoExento,
        public string $descuentoNoSujeto,
        public string $descuentoTotal,
        public string $ivaTotal,
        public string $totalPagar,
        // Exportación (tipo 11). En CCF/Factura quedan en cero.
        public string $totalExportacion = '0.00',
        public string $descuentoExportacion = '0.00',
        public string $flete = '0.00',
        public string $seguro = '0.00',
        // Retención de IVA (CCF a agente de retención). 0 si no aplica.
        public bool $aplicaRetencion = false,
        public string $porcentajeRetencion = '0.00',
        public string $baseRetencion = '0.00',
        public string $retencionIva = '0.00',
        // total_pagar arriba ya viene NETO de retención; este guarda el bruto.
        public ?string $totalAntesRetencion = null,
    ) {}
}
