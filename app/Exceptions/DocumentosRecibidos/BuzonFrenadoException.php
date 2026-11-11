<?php

namespace App\Exceptions\DocumentosRecibidos;

/**
 * El servidor frenó los logins seguidos («Too many login failures», #162). Es pasajero:
 * la contraseña está bien y basta con esperar unos minutos. Es DISTINTO de credenciales
 * rechazadas ({@see AutenticacionBuzonException}): ahí hay que ir a Configuración, acá
 * volver a intentar enseguida solo alarga el freno.
 */
class BuzonFrenadoException extends BuzonException
{
    public static function esperar(int $minutos, ?\Throwable $previa = null): self
    {
        return new self(
            'Yahoo pidió esperar unos minutos antes de volver a entrar al correo (demasiados intentos seguidos). '
            .'Probá de nuevo en '.$minutos.' minutos; no hace falta cambiar la contraseña.',
            previous: $previa,
        );
    }

    public function clave(): string
    {
        return 'buzon_frenado';
    }
}
