<?php

namespace App\Services\Cobros\AuditorEnviados;

/**
 * Un DTE ya decodificado desde el JSON adjunto de un correo enviado (03/05 o
 * cualquier otro tipo). Es la unidad mínima que recibe {@see AnalizadorAuditoriaEnviados};
 * no sabe nada de Gmail ni de cómo se llegó a estos valores.
 */
final class AdjuntoDteLeido
{
    /**
     * @param  array<int, string>  $documentoRelacionadoCodigos  códigos de generación de los
     *                                                           documentos relacionados declarados en el propio JSON (el CCF original, solo en NC).
     *                                                           Nunca inferidos por importe/fecha/OC.
     */
    public function __construct(
        public readonly ?string $tipoDte,
        public readonly ?string $numeroControl,
        public readonly ?string $codigoGeneracion,
        public readonly ?string $receptorNit,
        public readonly array $documentoRelacionadoCodigos = [],
    ) {}
}
