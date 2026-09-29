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
 *  - opcionalmente el código de generación del documento SUSTITUTO.
 *
 * Estructura interna: no usa los nombres del schema. El serializador
 * {@see \App\Services\Dte\Serializadores\SerializadorInvalidacionMh} los mapea.
 *
 * NO incluye el `codigoGeneracion` del evento: ese lo genera el serializador como
 * UUID NUEVO (distinto al del DTE invalidado) en cada corrida.
 *
 * ── Sustituto: cuándo corresponde ─────────────────────────────────────────────
 * NO lo decide este objeto ni el motivo por su cuenta: depende del tipo de documento
 * que se invalida. Lo resuelve {@see \App\Support\Dte\PoliticaInvalidacion} y lo
 * verifica {@see \App\Services\Dte\ValidadorReglasInvalidacion}. Aquí solo se NORMALIZA
 * la entrada: una cadena vacía o con espacios equivale a «sin sustituto» y se guarda
 * como null, para que un campo de formulario vacío nunca se confunda con un valor
 * enviado indebidamente. El código se guarda en mayúsculas, como lo exige el MH.
 */
final readonly class EventoInvalidacionData
{
    public ?string $motivoAnulacion;

    public ?string $codigoGeneracionReemplazo;

    public function __construct(
        public TipoAnulacionMh $tipoAnulacion,
        public ?string $nombreResponsable = null,
        public ?string $tipoDocResponsable = null,
        public ?string $numDocResponsable = null,
        public ?string $nombreSolicita = null,
        public ?string $tipoDocSolicita = null,
        public ?string $numDocSolicita = null,
        // Texto libre; el MH lo exige para tipo 3 (Otro).
        ?string $motivoAnulacion = null,
        // Código de generación del DTE sustituto. Va en null en todas las celdas de la
        // matriz que lo prohíben; enviarlo ahí es un error que se rechaza en servidor.
        ?string $codigoGeneracionReemplazo = null,
    ) {
        $motivo = trim((string) $motivoAnulacion);
        $this->motivoAnulacion = $motivo === '' ? null : $motivo;

        $reemplazo = strtoupper(trim((string) $codigoGeneracionReemplazo));
        $this->codigoGeneracionReemplazo = $reemplazo === '' ? null : $reemplazo;
    }
}
