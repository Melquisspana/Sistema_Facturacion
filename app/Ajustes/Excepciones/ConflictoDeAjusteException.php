<?php

namespace App\Ajustes\Excepciones;

use RuntimeException;

/**
 * Otro administrador cambió el mismo ajuste entre que este usuario abrió el
 * formulario y le dio a guardar (comprobación optimista por `updated_at`).
 * Ver docs/CENTRO_CONFIGURACION.md, sección de concurrencia.
 */
class ConflictoDeAjusteException extends RuntimeException
{
    public static function para(string $clave): self
    {
        return new self(
            "El ajuste «{$clave}» fue modificado por otra persona mientras editabas. Recargá la pantalla y volvé a intentarlo."
        );
    }
}
