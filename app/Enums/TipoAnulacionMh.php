<?php

namespace App\Enums;

/**
 * Tipo de invalidación oficial ante el MH — catálogo CAT-024 (importado en
 * `catalogos_mh`, sección '024'). NO confundir con {@see MotivoAnulacion}, que es
 * la anulación INTERNA/preliminar de un documento generado.
 *
 * Valores OFICIALES confirmados desde CAT-024 (catalogos_mh):
 *  1 = «Error en la Información del Documento Tributario Electrónico a invalidar.»
 *  2 = «Rescindir de la operación realizada.»
 *  3 = «Otro»
 *
 * Regla de negocio del evento de invalidación (schema invalidacion-schema-v3):
 *  - tipo 1 EXIGE `documento.codigoGeneracionR` (código de generación del DTE que
 *    reemplaza al invalidado). El schema lo declara nullable, así que esta regla la
 *    impone {@see \App\Services\Dte\Serializadores\SerializadorInvalidacionMh}.
 *  - tipos 2 y 3 NO llevan documento de reemplazo (codigoGeneracionR = null).
 *  - tipo 3 requiere `motivo.motivoAnulacion` en texto libre.
 *
 * TODO (pendiente de confirmar en el Manual Técnico del MH, no está en el repo en
 * texto): qué tipo corresponde exactamente para invalidar una NC tipo 05 aceptada y
 * si aplica una ventana de tiempo. Mientras no se confirme, el tipo se pasa de forma
 * EXPLÍCITA (no se asume) y la ventana de tiempo NO se valida aquí.
 */
enum TipoAnulacionMh: int
{
    case ErrorInformacion = 1;
    case RescindirOperacion = 2;
    case Otro = 3;

    public function label(): string
    {
        return match ($this) {
            self::ErrorInformacion => 'Error en la Información del Documento Tributario Electrónico a invalidar.',
            self::RescindirOperacion => 'Rescindir de la operación realizada.',
            self::Otro => 'Otro',
        };
    }

    /** ¿Este tipo exige el código de generación del documento de reemplazo? (solo tipo 1). */
    public function requiereDocumentoReemplazo(): bool
    {
        return $this === self::ErrorInformacion;
    }

    /** ¿Este tipo exige un motivo en texto libre? (solo tipo 3). */
    public function requiereMotivoTexto(): bool
    {
        return $this === self::Otro;
    }

    /** @return array<int, string> [valor => label] para selects/validación. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
