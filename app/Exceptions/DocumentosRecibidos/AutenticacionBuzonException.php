<?php

namespace App\Exceptions\DocumentosRecibidos;

/**
 * El servidor respondió y rechazó usuario/contraseña. Reintentar no sirve: hace falta
 * corregir las credenciales en Configuración > Integraciones > Documentos recibidos.
 */
class AutenticacionBuzonException extends BuzonException
{
    public function clave(): string
    {
        return 'autenticacion_fallida';
    }
}
