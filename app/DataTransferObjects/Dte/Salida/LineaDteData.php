<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Una línea del cuerpo del DTE (estructura interna). Importes como cadenas
 * decimales (estrategia de dinero exacto). `dteLineaOriginalId` solo aplica a
 * notas de crédito (línea del documento original acreditada).
 */
final readonly class LineaDteData
{
    public function __construct(
        public int $numeroLinea,
        public string $descripcion,
        public string $cantidad,
        public string $precioUnitario,
        public string $totalLinea,
        public ?int $tipoItem = null,
        public ?string $codigo = null,
        public ?string $codigoBarra = null,
        public ?string $unidadMedida = null,
        public string $descuento = '0.00',
        public string $ventaGravada = '0.00',
        public string $ventaExenta = '0.00',
        public string $ventaNoSujeta = '0.00',
        public string $ventaExportacion = '0.00',
        public string $iva = '0.00',
        public ?int $dteLineaOriginalId = null,
    ) {}
}
