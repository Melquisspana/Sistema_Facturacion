<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * Se intentó alterar o eliminar una ubicación de SISTEMA.
 *
 * `es_sistema = true` marca las ubicaciones que el módulo necesita para
 * funcionar, no las que alguien decidió crear. Hoy la única es TRÁNSITO, y de
 * ella dependen los traslados: el servicio exige que exista EXACTAMENTE una de
 * tipo `transito`, de sistema, activa y sin operación manual.
 *
 * Por eso están protegidas cuatro cosas:
 *
 *   - el CÓDIGO, que es como se la identifica;
 *   - el TIPO, que es lo que la hace ser de tránsito;
 *   - la bandera `es_sistema`, que es lo que la distingue de una bodega normal;
 *   - la DESACTIVACIÓN, porque una ubicación de sistema inactiva no la encuentra
 *     el resolutor, y el efecto sería que los traslados dejan de poder enviarse
 *     con un mensaje sobre «no existe el tránsito» que nadie relacionaría con
 *     haber tocado una casilla en un formulario.
 *
 * También queda bloqueada `permite_operacion_manual`: activarla en el tránsito
 * permitiría recibir o ajustar mercancía directamente ahí, que es exactamente lo
 * que la ubicación existe para impedir.
 *
 * LÍMITE HONESTO: los eventos de Eloquent solo corren si la escritura pasa por
 * una instancia del modelo. `PlantaUbicacion::query()->update(...)` y el SQL
 * crudo NO los disparan. Es defensa en profundidad frente al uso normal de la
 * aplicación, no una garantía del motor.
 */
class UbicacionSistemaProtegidaException extends RuntimeException
{
    public static function campoProtegido(string $codigo, string $campo): self
    {
        return new self(
            "La ubicación de sistema «{$codigo}» no admite cambiar «{$campo}». El módulo depende de "
            .'ella para funcionar: los traslados necesitan encontrar exactamente una ubicación de '
            .'tránsito de sistema, activa y sin operación manual.'
        );
    }

    public static function desactivar(string $codigo): self
    {
        return new self(
            "La ubicación de sistema «{$codigo}» no puede desactivarse. Sin ella los traslados no "
            .'encuentran dónde dejar la mercancía en viaje y dejan de poder enviarse.'
        );
    }

    public static function eliminar(string $codigo): self
    {
        return new self(
            "La ubicación de sistema «{$codigo}» no puede eliminarse: la crea el módulo y de ella "
            .'depende el saldo en tránsito.'
        );
    }
}
