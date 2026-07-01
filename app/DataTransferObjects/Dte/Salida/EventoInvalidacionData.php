<?php

namespace App\DataTransferObjects\Dte\Salida;

use App\Enums\TipoAnulacionMh;

/**
 * Datos NUEVOS del evento de invalidación (bloque `motivo` del schema
 * invalidacion-schema-v3), que NO viven en el DTE original y los aporta quien
 * ejecuta la invalidación:
 *  - el tipo de anulación (CAT-024),
 *  - el motivo en texto (obligatorio para tipo 3),
 *  - los datos del RESPONSABLE (quien realiza el evento) y del SOLICITANTE
 *    (quien lo pide),
 *  - opcionalmente el código de generación del documento de REEMPLAZO (obligatorio
 *    para tipo 1).
 *
 * Estructura interna: no usa los nombres del schema. El serializador
 * {@see \App\Services\Dte\Serializadores\SerializadorInvalidacionMh} los mapea.
 *
 * NO incluye el `codigoGeneracion` del evento: ese lo genera el serializador como
 * UUID NUEVO (distinto al del DTE invalidado) en cada corrida.
 */
final readonly class EventoInvalidacionData
{
    public function __construct(
        public TipoAnulacionMh $tipoAnulacion,
        public ?string $nombreResponsable = null,
        public ?string $tipoDocResponsable = null,
        public ?string $numDocResponsable = null,
        public ?string $nombreSolicita = null,
        public ?string $tipoDocSolicita = null,
        public ?string $numDocSolicita = null,
        // Texto libre; el MH lo exige para tipo 3 (Otro).
        public ?string $motivoAnulacion = null,
        // Código de generación del DTE que reemplaza al invalidado; el MH lo exige
        // para tipo 1 (Error en la información). Para tipos 2 y 3 debe ir null.
        public ?string $codigoGeneracionReemplazo = null,
    ) {}
}
