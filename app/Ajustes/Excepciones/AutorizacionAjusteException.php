<?php

namespace App\Ajustes\Excepciones;

use App\Ajustes\Definicion\DefinicionAjuste;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Falta el permiso que exige el NIVEL del ajuste. Hereda de AuthorizationException
 * para que Laravel la convierta en 403 sin manejo extra en los controladores.
 */
class AutorizacionAjusteException extends AuthorizationException
{
    public static function para(DefinicionAjuste $definicion): self
    {
        return new self(
            "Se requiere el permiso «{$definicion->nivel->permisoRequerido()->value}» para cambiar «{$definicion->clave}»."
        );
    }
}
