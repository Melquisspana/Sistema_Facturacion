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
 * ── Qué NO decide este enum ───────────────────────────────────────────────────
 * Si hace falta documento SUSTITUTO no depende solo del motivo: depende también del
 * TIPO DE DOCUMENTO que se invalida. Una NC 05 no lleva sustituto en ninguno de los
 * tres motivos, mientras que FE/CCF/FEX lo exigen en los motivos 1 y 3 (Manual
 * Funcional v2.0, págs. impresas 13-16). Por eso esa regla vive en
 * {@see \App\Support\Dte\PoliticaInvalidacion} y aquí se eliminó el antiguo
 * `requiereDocumentoReemplazo()`, que contestaba sin conocer el documento y por eso
 * exigía sustituto para todo motivo 1 y lo prohibía para el motivo 3.
 *
 * Lo que sí es una regla del MOTIVO, sin contexto documental, es el texto libre: el
 * tipo 3 exige `motivo.motivoAnulacion` en cualquier tipo de documento.
 *
 * Los PLAZOS de transmisión del evento tampoco se validan aquí ni en ningún otro punto
 * todavía: siguen pendientes por una inconsistencia de la fuente (manual, págs. 11-12).
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

    /** ¿Este tipo exige un motivo en texto libre? (solo tipo 3, en cualquier documento). */
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
