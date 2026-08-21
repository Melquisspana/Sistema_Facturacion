<?php

namespace App\Ajustes\Excepciones;

use RuntimeException;

/**
 * Se intentó ESCRIBIR un ajuste antes de que existiera la tabla que lo guarda.
 *
 * Solo puede pasar en la ventana entre subir el código y correr `migrate`: el
 * código nuevo ya sabe que `contabilidad.correo` se escribe en `ajustes_sistema`,
 * pero el esquema viejo todavía no tiene esa tabla.
 *
 * LEER en esa ventana está resuelto y no pasa por acá: el repositorio de ajustes
 * devuelve un mapa vacío y cada clave cae a la tabla anterior, a config/.env o a su
 * valor por defecto. ESCRIBIR no tiene equivalente —no hay dónde poner el valor— y
 * la única respuesta honesta es decir que todavía no se puede.
 *
 * Existe para que eso NO sea un 500 con una excepción de SQL. Un administrador que
 * guarda durante un despliegue tiene que leer «volvé a intentarlo en un momento», no
 * una traza con el nombre de la base; y quien opera el despliegue tiene que saber que
 * lo que falta es exactamente `php artisan migrate`.
 *
 * NO SE PIERDE NADA: la escritura se rechaza entera antes de tocar la base, así que
 * la configuración anterior sigue intacta y sigue resolviéndose por la tabla anterior.
 */
class AlmacenAjustesNoDisponibleException extends RuntimeException
{
    public static function tabla(string $tabla): self
    {
        return new self(
            'La configuración todavía no se puede guardar en este servidor: falta la tabla «'.$tabla.'». '
            .'El despliegue está a medias (falta ejecutar las migraciones). Volvé a intentarlo en unos minutos; '
            .'la configuración actual no se ha perdido.'
        );
    }
}
