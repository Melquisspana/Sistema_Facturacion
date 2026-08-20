<?php

namespace App\Ajustes\Rotacion;

use RuntimeException;

/**
 * La rotación no puede aplicarse sin perder datos, así que no se aplicó nada.
 *
 * El mensaje nombra los ajustes afectados —nunca sus valores— porque lo que
 * necesita saber quien la ejecuta es CUÁLES revisar, no qué contienen.
 */
class RotacionImposibleException extends RuntimeException
{
    public function __construct(
        public readonly InformeRotacion $informe,
        string $mensaje,
    ) {
        parent::__construct($mensaje);
    }

    public static function por(InformeRotacion $informe): self
    {
        $motivos = [];

        if ($informe->ilegibles !== []) {
            $motivos[] = 'no se pueden descifrar con la clave actual: '.implode(', ', $informe->ilegibles);
        }

        if ($informe->noVerificados !== []) {
            $motivos[] = 'no superaron la comprobación con la clave nueva: '.implode(', ', $informe->noVerificados);
        }

        return new self(
            $informe,
            'Rotación abortada SIN escribir nada. '.implode('; ', $motivos).'.'
        );
    }
}
