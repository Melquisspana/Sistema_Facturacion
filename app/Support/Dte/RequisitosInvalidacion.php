<?php

namespace App\Support\Dte;

use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;

/**
 * Qué exige —y qué prohíbe— el evento de invalidación para UNA combinación concreta de
 * TIPO DE DOCUMENTO (CAT-002) y MOTIVO (CAT-024). Es el resultado de
 * {@see PoliticaInvalidacion::requisitos()} y la ÚNICA forma de esa regla en todo el
 * sistema: formulario, Form Request, preview/preflight, serializador, mock y transmisión
 * real leen este objeto en vez de reimplementar la matriz.
 *
 * `soportado = false` NO significa «sin requisitos»: significa que el sistema no sabe qué
 * exige el MH para ese documento y por eso NO se invalida por esta vía (ver
 * {@see $razonNoSoportado}). Nunca se cae en la regla del CCF por defecto.
 */
final readonly class RequisitosInvalidacion
{
    public function __construct(
        public TipoAnulacionMh $motivo,
        public ?TipoDte $documento,
        /** ¿El sistema tiene regla oficial para esta combinación? */
        public bool $soportado,
        /** ¿Exige `documento.codigoGeneracionR` (sustituto previamente aceptado por el MH)? */
        public bool $requiereReemplazo,
        /** ¿Exige `motivo.motivoAnulacion` en texto libre? */
        public bool $requiereMotivoTexto,
        /** Tipos de DTE admisibles como sustituto; vacío cuando no se exige sustituto. */
        public array $tiposSustituto = [],
        /** Por qué NO se puede invalidar este documento por esta vía (solo si ! soportado). */
        public ?string $razonNoSoportado = null,
        /** Aclaración operativa para quien factura (p. ej. el orden NC: invalidar y luego corregir). */
        public ?string $notaOperativa = null,
    ) {}

    /**
     * ¿Esta combinación EXIGE que `codigoGeneracionR` viaje en null? Es el complemento
     * exacto de {@see $requiereReemplazo} dentro de lo soportado: la matriz del manual no
     * deja ninguna celda «opcional».
     */
    public function prohibeReemplazo(): bool
    {
        return $this->soportado && ! $this->requiereReemplazo;
    }

    /** Forma que consume la UI (Alpine) y el JSON del asistente. Sin lógica propia. */
    public function paraUi(): array
    {
        return [
            'soportado' => $this->soportado,
            'requiere_reemplazo' => $this->requiereReemplazo,
            'requiere_motivo' => $this->requiereMotivoTexto,
            'razon_no_soportado' => $this->razonNoSoportado,
            'nota_operativa' => $this->notaOperativa,
        ];
    }
}
