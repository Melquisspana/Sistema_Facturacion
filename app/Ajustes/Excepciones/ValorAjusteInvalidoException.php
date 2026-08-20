<?php

namespace App\Ajustes\Excepciones;

use Illuminate\Support\MessageBag;
use RuntimeException;

/**
 * El valor propuesto no pasó la validación de su tipo/reglas. Lleva un MessageBag
 * para que un controlador futuro pueda devolverlo al formulario tal cual.
 */
class ValorAjusteInvalidoException extends RuntimeException
{
    public function __construct(
        public readonly string $clave,
        public readonly MessageBag $errores,
    ) {
        parent::__construct("Valor inválido para el ajuste «{$clave}»: ".$errores->first());
    }

    public static function con(string $clave, string $mensaje): self
    {
        return new self($clave, new MessageBag([$clave => [$mensaje]]));
    }
}
