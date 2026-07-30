<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * Se pidió el lote genérico de un insumo que SÍ controla lotes, o se intentó
 * modificar o eliminar un lote genérico ya creado.
 *
 * El genérico existe para que un insumo sin trazabilidad por lote —bolsas,
 * viñetas— pueda tener bucket sin inventar identidades falsas. Dárselo a un
 * insumo trazable destruiría precisamente la trazabilidad que ese insumo exige.
 */
class LoteGenericoNoAplicableException extends RuntimeException
{
    public static function insumoControlaLotes(int $insumoId): self
    {
        return new self(
            "El insumo #{$insumoId} controla lotes: su saldo exige un lote real trazable y no "
            .'admite lote genérico.'
        );
    }

    public static function inmutable(int $loteId, string $operacion): self
    {
        return new self(
            "El lote genérico #{$loteId} no admite {$operacion}: es un lote de sistema, único por "
            .'insumo y con la fecha de la operación que lo creó.'
        );
    }
}
