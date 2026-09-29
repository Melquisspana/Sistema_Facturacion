<?php

namespace App\Services\Cobros\ImportadorEnviados;

/**
 * Un DTE leído del JSON adjunto a un correo enviado, reducido a lo que la importación
 * necesita. No lleva el JSON ni el nombre del receptor: solo identidad fiscal, importe,
 * fecha, la huella del adjunto y las relaciones que el propio JSON declara.
 */
final class AdjuntoImportable
{
    /**
     * @param  array<int, string>  $identificadoresReceptor  NIT/numDocumento del receptor, ya solo dígitos y sin repetir
     * @param  array<int, array{tipoDocumento: ?string, numeroDocumento: ?string, fechaEmision: ?string}>  $relacionados
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $adjuntoNombre,
        public readonly string $adjuntoHash,
        public readonly ?string $tipoDte,
        public readonly ?string $numeroControl,
        public readonly ?string $codigoGeneracion,
        public readonly ?string $sello,
        public readonly ?string $fechaEmision,
        public readonly ?float $monto,
        public readonly array $identificadoresReceptor,
        public readonly array $relacionados,
    ) {}
}
