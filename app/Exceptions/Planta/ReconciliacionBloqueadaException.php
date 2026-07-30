<?php

namespace App\Exceptions\Planta;

use App\Services\Planta\ReconciliacionExistenciasService;
use RuntimeException;

/**
 * Ya hay una reconciliación con `--apply` en curso.
 *
 * Dos reconciliadores simultáneos leerían el mismo mayor y escribirían la misma
 * proyección; el segundo no corrompería nada por sí solo, pero sí dejaría un
 * informe y un registro de auditoría que contradicen al primero. Se rechaza la
 * segunda ejecución en vez de dejar que compitan.
 *
 * El candado es COOPERATIVO y solo protege entre reconciliadores. Lo que impide
 * que `--apply` pise a un movimiento en curso es otra cosa: el bloqueo de
 * `planta_existencias` dentro de la transacción. Ver
 * {@see ReconciliacionExistenciasService}.
 */
class ReconciliacionBloqueadaException extends RuntimeException
{
    public static function enCurso(): self
    {
        return new self(
            'Ya hay una reconciliación de existencias en curso. Espera a que termine: '
            .'dos pasadas simultáneas producirían informes contradictorios.'
        );
    }
}
