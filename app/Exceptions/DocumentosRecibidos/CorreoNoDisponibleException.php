<?php

namespace App\Exceptions\DocumentosRecibidos;

/**
 * El servidor de correo contestó «[UNAVAILABLE]» (RFC 5530): una caída suya de unos
 * minutos, no credenciales malas. Gmail lo dice como «[UNAVAILABLE] AUTHENTICATE Service is
 * temporarily not available» (16/11/2026). La contraseña está bien y la corrida siguiente
 * entra, así que la tarea programada no lo cuenta como caída. Es un buzón inaccesible: quien
 * ya atrapa {@see BuzonInaccesibleException} lo sigue tratando como «reintentá».
 */
class CorreoNoDisponibleException extends BuzonInaccesibleException
{
    public function clave(): string
    {
        return 'correo_no_disponible';
    }
}
