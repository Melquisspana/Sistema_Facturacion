<?php

namespace App\Ajustes\Excepciones;

use RuntimeException;

/**
 * Se pidió leer o escribir una clave que NO está en el registry.
 *
 * Es el candado central de la capa: sin él, `Ajustes::get($request->clave)`
 * convertiría cualquier cadena enviada por el navegador en una lectura arbitraria
 * de configuración. La lista blanca es la única forma de existir de un ajuste.
 */
class AjusteDesconocidoException extends RuntimeException
{
    public static function para(string $clave): self
    {
        return new self("El ajuste «{$clave}» no está declarado en el catálogo de configuración.");
    }
}
