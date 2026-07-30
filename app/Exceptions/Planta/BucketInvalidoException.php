<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El bucket de cinco dimensiones no cumple alguna invariante del inventario.
 *
 * Cubre lo que ninguna clave foránea puede expresar:
 *   - `planta_traslado_id > 0` en una ubicación física;
 *   - `planta_traslado_id = 0` en la ubicación de tránsito;
 *   - un lote que no pertenece al insumo del bucket;
 *   - un lote genérico usado por un insumo que sí controla lotes;
 *   - un lote real usado por un insumo que no controla lotes.
 */
class BucketInvalidoException extends RuntimeException
{
    public static function trasladoEnUbicacionFisica(int $ubicacionId, int $trasladoId): self
    {
        return new self(
            "La ubicación física #{$ubicacionId} no puede almacenar saldo del traslado #{$trasladoId}: "
            .'fuera de tránsito, planta_traslado_id debe ser 0.'
        );
    }

    public static function transitoSinTraslado(int $ubicacionId): self
    {
        return new self(
            "El saldo en la ubicación de tránsito #{$ubicacionId} debe ir ligado a un traslado: "
            .'planta_traslado_id no puede ser 0.'
        );
    }

    public static function trasladoNegativo(int $trasladoId): self
    {
        return new self("planta_traslado_id no puede ser negativo (recibido: {$trasladoId}).");
    }

    public static function loteAjeno(int $loteId, int $insumoId): self
    {
        return new self("El lote #{$loteId} no pertenece al insumo #{$insumoId}.");
    }

    public static function genericoEnInsumoConLotes(int $loteId, int $insumoId): self
    {
        return new self(
            "El lote genérico #{$loteId} no puede usarse: el insumo #{$insumoId} controla lotes "
            .'y exige un lote real trazable.'
        );
    }

    public static function loteRealEnInsumoSinLotes(int $loteId, int $insumoId): self
    {
        return new self(
            "El insumo #{$insumoId} no controla lotes: su saldo vive en el lote genérico, "
            ."no en el lote real #{$loteId}."
        );
    }
}
