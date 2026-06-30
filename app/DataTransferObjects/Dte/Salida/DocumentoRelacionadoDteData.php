<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Documento relacionado (estructura interna). Lo usa la Nota de crédito para
 * referenciar el CCF original. `numeroDocumento` será, en el JSON oficial, el
 * código de generación del documento original (cuando exista emisión real).
 */
final readonly class DocumentoRelacionadoDteData
{
    public function __construct(
        public string $tipoDocumento,
        public int $tipoGeneracion,
        public string $numeroDocumento,
        public string $fechaEmision,
    ) {}
}
