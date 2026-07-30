<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * Se intentó escribir en `planta_existencias` a través del modelo Eloquent.
 *
 * Las existencias son una PROYECCIÓN del mayor, no un dato editable: cambiar un
 * saldo sin el movimiento que lo justifica produce exactamente la corrupción que
 * la reconciliación tiene que ir a buscar después. El único escritor es
 * PlantaInventarioService, que trabaja con el query builder dentro de la misma
 * transacción que inserta el movimiento.
 */
class ExistenciaNoEscribibleException extends RuntimeException
{
    public static function crear(string $operacion): self
    {
        return new self(
            "Las existencias de Planta no admiten {$operacion} por Eloquent: son una proyección del "
            .'libro mayor. Cambia el saldo aplicando un movimiento con PlantaInventarioService, o '
            .'reconstruye la proyección con `planta:reconciliar-existencias --apply`.'
        );
    }
}
