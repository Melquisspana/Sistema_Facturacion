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
 * Además de los campos combinados "código — descripción" (histórico, usado por
 * vistas ya existentes), expone el código y la descripción por separado
 * (`*_codigo` / `*_valor`) para que la UI pueda mostrar el texto amigable como
 * dato principal y el código técnico en segundo plano (p. ej. "Aduana de salida:
 * Anguiatú" en vez de "08 — Terrestre Anguiatú" como único texto).
 *
 * Solo lectura/presentación: no recalcula nada fiscal, no modifica el DTE.
 */
final class DatosExportacionPresentacion
{
    /**
     * "Régimen" (CAT-028) es un CÓDIGO de clasificación aduanera (1000.000 =
     * Exportación definitiva, Régimen común), no un monto. Se muestra aparte para
     * evitar que se lea como un valor monetario.
     */
    public const AYUDA_REGIMEN = '1000.000 corresponde a Exportación definitiva — Régimen común.';

    /**
     * @return array{
     *     tipo_item: string,
     *     recinto_fiscal: ?string, recinto_fiscal_codigo: ?string, recinto_fiscal_valor: ?string,
     *     tipo_regimen: ?string, tipo_regimen_codigo: ?string, tipo_regimen_valor: ?string,
     *     regimen: ?string, regimen_codigo: ?string, regimen_valor: ?string,
     *     incoterm: ?string, incoterm_codigo: ?string, incoterm_valor: ?string,
     * }|null null si el documento no es Factura de exportación (tipo 11).
     */
    public static function resolver(Dte $dte): ?array
    {
        if ($dte->tipo_dte !== TipoDte::FacturaExportacion) {
            return null;
        }

        $recinto = self::partes('027', $dte->recinto_fiscal);
        $regimenTipo = self::partes('033', $dte->tipo_regimen);
        $regimen = self::partes('028', $dte->regimen);
        $incoterm = ['codigo' => $dte->cod_incoterms, 'valor' => $dte->desc_incoterms];

        return [
            'tipo_item' => TipoItemExportacion::tryFrom((int) $dte->tipo_item_expor)?->label() ?? '—',

            'recinto_fiscal' => self::combinar($recinto),
            'recinto_fiscal_codigo' => $recinto['codigo'],
            'recinto_fiscal_valor' => $recinto['valor'],

            'tipo_regimen' => self::combinar($regimenTipo),
            'tipo_regimen_codigo' => $regimenTipo['codigo'],
            'tipo_regimen_valor' => $regimenTipo['valor'],

            'regimen' => self::combinar($regimen),
            'regimen_codigo' => $regimen['codigo'],
            'regimen_valor' => $regimen['valor'],

            'incoterm' => self::combinar($incoterm),
            'incoterm_codigo' => $incoterm['codigo'],
            'incoterm_valor' => $incoterm['valor'],
        ];
    }

    /** @return array{codigo: ?string, valor: ?string} */
    private static function partes(string $cat, ?string $codigo): array
    {
        if (blank($codigo)) {
            return ['codigo' => null, 'valor' => null];
        }

        $valor = CatalogoMh::where('cat', $cat)->where('codigo', $codigo)->value('valor');

        return ['codigo' => $codigo, 'valor' => $valor];
    }

    /** @param  array{codigo: ?string, valor: ?string}  $partes */
    private static function combinar(array $partes): ?string
    {
        if (blank($partes['codigo'])) {
            return null;
        }

        return $partes['valor'] ? "{$partes['codigo']} — {$partes['valor']}" : $partes['codigo'];
    }
}
