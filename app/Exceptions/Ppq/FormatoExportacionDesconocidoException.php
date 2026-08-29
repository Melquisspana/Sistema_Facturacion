<?php

namespace App\Exceptions\Ppq;

use RuntimeException;

/**
 * El perfil de un cliente pide un formato de exportación que no está implementado.
 *
 * Pasa cuando alguien escribe un slug a mano en el perfil, o cuando se retira una
 * implementación sin migrar los perfiles que la usaban. Falla ruidosamente a propósito:
 * generar un archivo "por defecto" mandaría al cliente un Excel con el formato de otro.
 */
class FormatoExportacionDesconocidoException extends RuntimeException
{
    /** @param array<int, string> $disponibles */
    public static function para(string $slug, array $disponibles): self
    {
        $lista = $disponibles === [] ? 'ninguno' : implode(', ', $disponibles);

        return new self(
            $slug === ''
                ? "El perfil del cliente no tiene formato de exportación configurado. Formatos disponibles: {$lista}."
                : "No existe el formato de exportación «{$slug}». Formatos disponibles: {$lista}."
        );
    }
}
