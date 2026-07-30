<?php

namespace App\Exceptions\Planta;

use App\Services\Planta\PlantaInventarioService;
use RuntimeException;

/**
 * La cantidad del movimiento no es admisible, con independencia del saldo que
 * haya en el bucket.
 *
 * Separada de {@see BucketInvalidoException} a propósito: aquella habla de
 * DÓNDE se apunta el efecto, esta de QUÉ se apunta.
 *
 * NO cubre la unidad: la unidad no la elige el llamador, se lee del insumo, así
 * que no hay ningún valor de entrada que pueda ser inválido. Ver
 * {@see PlantaInventarioService}.
 */
class MovimientoInvalidoException extends RuntimeException
{
    public static function cantidadCero(): self
    {
        return new self('Un movimiento con cantidad 0 no registra ningún hecho: no se escribe en el mayor.');
    }

    public static function cantidadNoNumerica(string $cantidad): self
    {
        return new self("La cantidad '{$cantidad}' no es un decimal válido.");
    }

    public static function escalaExcedida(string $cantidad, int $escala): self
    {
        return new self(
            "La cantidad '{$cantidad}' excede los {$escala} decimales que almacena el inventario: "
            .'redondear aquí falsearía el saldo en silencio.'
        );
    }

    public static function fraccionNoPermitida(string $cantidad, int $insumoId): self
    {
        return new self(
            "El insumo #{$insumoId} no admite fracción: la cantidad '{$cantidad}' debe ser entera."
        );
    }
}
