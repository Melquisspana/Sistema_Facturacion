<?php

namespace App\Ajustes\Definicion;

/**
 * DÓNDE se escribe el override de un ajuste. Cada clave declara UNA sola
 * ubicación de escritura; nunca dos.
 *
 * Esta es la respuesta al riesgo de "el mismo valor guardado en dos tablas": si
 * una clave se escribe en `configuraciones` (Legacy), la tabla nueva ni siquiera
 * se consulta para ella, y al revés. La migración de una clave de Legacy a Nueva
 * será un cambio EXPLÍCITO de este enum acompañado de su traslado de datos, no
 * una duplicación silenciosa.
 */
enum Persistencia: string
{
    /** Tabla nueva `ajustes_sistema` (soporta cifrado). */
    case Nueva = 'nueva';

    /** Tabla `configuraciones` existente. Transición: no se migra todavía. */
    case Legacy = 'legacy';

    /** Sin override: el valor vive solo en config/.env y se lee de ahí. */
    case Ninguna = 'ninguna';

    public function admiteOverride(): bool
    {
        return $this !== self::Ninguna;
    }

    /** Solo la tabla nueva sabe guardar secretos cifrados. */
    public function admiteSecretos(): bool
    {
        return $this === self::Nueva;
    }
}
