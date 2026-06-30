<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Sección "receptor" del DTE (estructura interna). Todos los campos son opcionales
 * porque varían por tipo de documento (CCF completo, Factura 01 puede no llevar
 * receptor, exportación con país, etc.). La identidad fiscal proviene del cliente;
 * la ubicación (departamento/municipio/distrito/dirección) proviene de la sala de
 * entrega cuando el DTE tiene una. `sucursalNombre` es referencia comercial.
 */
final readonly class ReceptorDteData
{
    public function __construct(
        public ?string $tipoDocumento = null,
        public ?string $numDocumento = null,
        public ?string $nrc = null,
        public ?string $nombre = null,
        public ?string $nombreComercial = null,
        public ?string $actividadEconomica = null,
        public ?string $pais = null,
        public ?string $departamento = null,
        public ?string $municipio = null,
        public ?string $distrito = null,
        public ?string $direccion = null,
        public ?string $telefono = null,
        public ?string $correo = null,
        public ?string $tipoPersona = null,
        public ?string $sucursalNombre = null,
    ) {}
}
