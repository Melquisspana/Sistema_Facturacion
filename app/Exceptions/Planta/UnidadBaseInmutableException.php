<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * Se intentó cambiar la `unidad_base` de un insumo que ya tiene historial de
 * inventario.
 *
 * La unidad no es un rótulo del catálogo: es la unidad en la que están escritas
 * TODAS las cantidades de ese insumo. `planta_movimientos` guarda una copia
 * congelada al escribir cada asiento, y lo mismo hacen las líneas de recepción,
 * traslado y ajuste. `planta_existencias` no la guarda: la toma del catálogo por
 * JOIN. Cambiarla con historial dejaría el mayor diciendo «libras» y el saldo
 * presentándose como «unidades» sin que ninguna cifra hubiera cambiado.
 *
 * ES LA CAPA DEFENSIVA, no la que ve el usuario. El flujo HTTP lo detiene antes
 * en `InsumoRequest`, que devuelve un error de validación sobre el campo. Esta
 * excepción cubre lo que no pasa por el formulario: Tinker, un comando, un
 * seeder futuro o código que llame a `update()` directamente.
 *
 * Mismo límite honesto que el resto de candados de Eloquent del módulo: no cubre
 * `PlantaInsumo::query()->update(...)` ni el SQL crudo, porque esas rutas no
 * materializan el modelo. Es defensa en profundidad, no una garantía.
 */
class UnidadBaseInmutableException extends RuntimeException
{
    public static function tieneHistorial(int $insumoId, string $actual, string $nueva): self
    {
        return new self(
            "El insumo #{$insumoId} ya tiene historial de inventario: su unidad base no puede "
            ."pasar de «{$actual}» a «{$nueva}». Las cantidades ya registradas están expresadas "
            .'en la unidad actual y cambiarla las volvería ilegibles.'
        );
    }
}
