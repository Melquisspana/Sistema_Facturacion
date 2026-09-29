<?php

namespace App\Support\Dte;

use App\Models\Dte;
use App\Models\Empresa;

/**
 * Identidad del EMISOR de un documento, tal como la reconoce Hacienda: el NIT del
 * contribuyente, no la fila interna de `empresas`.
 *
 * Existe porque comparar `empresa_id` respondería a la pregunta equivocada. Un mismo
 * contribuyente puede tener más de un registro interno —migraciones, entornos, altas
 * repetidas— y seguiría siendo el MISMO emisor ante el MH; y al revés, dos filas
 * distintas con el mismo NIT no son dos emisores. Lo que el evento de invalidación
 * declara en `emisor.nit` es justamente este valor.
 *
 * Lo usan la verificación del documento sustituto
 * ({@see \App\Services\Dte\VerificadorDocumentoReemplazo}) y el buscador que ofrece los
 * candidatos ({@see \App\Services\Dte\BusquedaDocumentoReemplazo}), para que ofrecer y
 * aceptar signifiquen exactamente lo mismo.
 */
final class EmisorDte
{
    /** NIT del emisor del documento, solo dígitos. Null si no se puede resolver. */
    public static function nit(Dte $dte): ?string
    {
        $dte->loadMissing('establecimiento.empresa');

        $nit = preg_replace('/\D+/', '', (string) $dte->establecimiento?->empresa?->nit);

        return ($nit ?? '') === '' ? null : $nit;
    }

    /**
     * Ids de todas las empresas que comparten el NIT del emisor de `$dte`. Sirve para
     * filtrar en SQL sin tener que normalizar el NIT dentro de la consulta (los guiones
     * se escriben de formas distintas según quién dio de alta el registro).
     *
     * @return array<int, int> vacío si el emisor no se puede resolver
     */
    public static function empresasDelMismoEmisor(Dte $dte): array
    {
        $nit = self::nit($dte);

        if ($nit === null) {
            return [];
        }

        return Empresa::query()
            ->pluck('nit', 'id')
            ->filter(fn ($valor) => preg_replace('/\D+/', '', (string) $valor) === $nit)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
