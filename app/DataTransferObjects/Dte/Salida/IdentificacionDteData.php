<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Sección "identificación" del DTE (estructura INTERNA, previa al JSON oficial).
 * Los nombres son internos; el mapeo a los nombres del schema MH llega en el paso
 * de mappers, cuando los esquemas oficiales estén versionados.
 */
final readonly class IdentificacionDteData
{
    public function __construct(
        public int $version,
        public string $ambiente,
        public string $tipoDte,
        public string $fechaEmision,
        public string $horaEmision,
        public ?string $numeroControl = null,
        public ?string $codigoGeneracion = null,
        public int $tipoModelo = 1,
        public int $tipoOperacion = 1,
        public ?string $tipoContingencia = null,
        public ?string $motivoContingencia = null,
        public string $tipoMoneda = 'USD',
    ) {}
}
