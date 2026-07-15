<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoItemExportacion;
use App\Models\CatalogoMh;
use App\Models\Dte;

/**
 * Resuelve los datos FEX (tipo 11) ya guardados en el DTE a etiquetas legibles
 * para mostrar en ficha/PDF/imprimir: recinto fiscal (CAT-027), tipo de régimen
 * (CAT-033) y régimen (CAT-028) solo guardan el código en el DTE, así que se
 * completa la descripción desde catalogos_mh. El incoterm ya trae su descripción
 * guardada (cod_incoterms/desc_incoterms), no requiere lookup.
 *
 * Solo lectura/presentación: no recalcula nada fiscal, no modifica el DTE.
 */
final class DatosExportacionPresentacion
{
    /**
     * @return array{tipo_item: string, recinto_fiscal: ?string, tipo_regimen: ?string, regimen: ?string, incoterm: ?string}|null
     *               null si el documento no es Factura de exportación (tipo 11).
     */
    public static function resolver(Dte $dte): ?array
    {
        if ($dte->tipo_dte !== TipoDte::FacturaExportacion) {
            return null;
        }

        $incoterm = null;
        if (filled($dte->cod_incoterms)) {
            $incoterm = trim($dte->cod_incoterms.' — '.($dte->desc_incoterms ?? ''), ' —');
        }

        return [
            'tipo_item' => TipoItemExportacion::tryFrom((int) $dte->tipo_item_expor)?->label() ?? '—',
            'recinto_fiscal' => self::etiqueta('027', $dte->recinto_fiscal),
            'tipo_regimen' => self::etiqueta('033', $dte->tipo_regimen),
            'regimen' => self::etiqueta('028', $dte->regimen),
            'incoterm' => $incoterm,
        ];
    }

    private static function etiqueta(string $cat, ?string $codigo): ?string
    {
        if (blank($codigo)) {
            return null;
        }

        $valor = CatalogoMh::where('cat', $cat)->where('codigo', $codigo)->value('valor');

        return $valor ? "{$codigo} — {$valor}" : $codigo;
    }
}
