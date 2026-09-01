<?php

namespace App\Exceptions\DocumentosRecibidos;

/**
 * No se pudo llegar al buzón o la lectura se cortó a mitad: red caída, servidor que
 * no responde, carpeta inexistente, timeout. Es DISTINTO de credenciales rechazadas
 * ({@see AutenticacionBuzonException}) porque la acción del operador es otra: acá se
 * reintenta, allá hay que ir a Configuración.
 */
class BuzonInaccesibleException extends BuzonException
{
    public function clave(): string
    {
        return 'buzon_inaccesible';
    }
}
