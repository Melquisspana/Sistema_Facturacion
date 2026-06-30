<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Sección "emisor" del DTE (estructura interna). Datos fiscales del emisor
 * (empresa) más los códigos de establecimiento y punto de venta.
 */
final readonly class EmisorDteData
{
    public function __construct(
        public string $nit,
        public string $nrc,
        public string $nombre,
        public string $codigoEstablecimiento,
        public string $codigoPuntoVenta,
        public ?string $nombreComercial = null,
        public ?string $actividadEconomica = null,
        public ?string $departamento = null,
        public ?string $municipio = null,
        public ?string $distrito = null,
        public ?string $direccion = null,
        public ?string $telefono = null,
        public ?string $correo = null,
        public ?string $tipoEstablecimiento = null,
    ) {}
}
