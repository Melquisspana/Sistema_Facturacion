<?php

namespace App\Ajustes\Excepciones;

use App\Ajustes\Definicion\DefinicionAjuste;
use RuntimeException;

/** La clave existe y el usuario tiene permiso, pero el ajuste no se abre a escritura todavía. */
class AjusteNoEditableException extends RuntimeException
{
    public static function para(DefinicionAjuste $definicion): self
    {
        return new self(
            "El ajuste «{$definicion->clave}» no es editable desde la aplicación ({$definicion->editabilidad->etiqueta()})."
        );
    }
}
