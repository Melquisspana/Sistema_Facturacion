<?php

namespace App\Services\Cobros\AuditorEnviados;

/**
 * Conteos de la auditoría de solo lectura. Nunca lleva JSON crudo, correos ni datos
 * personales: como mucho, códigos de generación (identificadores de DTE, no de
 * personas) cuando ayudan a localizar un caso concreto.
 */
final class ResultadoAuditoriaEnviados
{
    /**
     * @param  array<int, string>  $ncSinCcfEnBarrido  códigos de generación de CCF que una NC de
     *                                                 Calleja declara como relacionado y que este barrido NO vio como CCF de Calleja. No es
     *                                                 una afirmación de que el CCF no exista: puede estar fuera del rango o del límite pedido.
     * @param  array<int, string>  $codigosDuplicados  códigos de generación vistos en más de un correo distinto.
     */
    public function __construct(
        public readonly int $correosRevisados,
        public readonly int $correosDuplicados,
        public readonly int $correosSinJsonLegible,
        public readonly int $correosReceptorDistinto,
        public readonly int $correosReceptorNoIdentificable,
        public readonly int $dtesTipoDistinto,
        public readonly int $dtesIncompletos,
        public readonly int $ccf,
        public readonly int $nc,
        public readonly int $ncSinRelacion,
        public readonly int $dtesRepetidosEnOtroCorreo,
        public readonly bool $truncado,
        public readonly array $ncSinCcfEnBarrido = [],
        public readonly array $codigosDuplicados = [],
    ) {}
}
