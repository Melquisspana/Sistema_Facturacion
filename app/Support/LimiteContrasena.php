<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Límite de intentos con la contraseña para quien ya está adentro (confirmarla, cambiarla
 * o cambiar el correo): 6 por minuto y por cuenta, como en el login.
 *
 * El intento se cuenta ANTES de comprobar la contraseña. Comprobarla tarda (bcrypt): si se
 * contara después, una ráfaga de pedidos simultáneos pasaría entera la revisión antes de que
 * se sumara un solo fallo. Quien acierta limpia el contador, y si lo que falló no fue la
 * contraseña, ese intento se devuelve: solo cuentan los fallos.
 */
final class LimiteContrasena
{
    public const INTENTOS = 6;

    private const SEGUNDOS = 60;

    /**
     * Cuenta el intento. Si ya pasó el límite, lo rechaza sin comprobar la contraseña, con
     * el aviso en ese campo del formulario.
     *
     * @throws ValidationException
     */
    public static function contar(string $clave, string $campo, string $bolsa = 'default'): void
    {
        if (RateLimiter::increment($clave, self::SEGUNDOS) <= self::INTENTOS) {
            return;
        }

        $segundos = RateLimiter::availableIn($clave);

        throw ValidationException::withMessages([
            $campo => __('auth.throttle', ['seconds' => $segundos, 'minutes' => ceil($segundos / 60)]),
        ])->errorBag($bolsa);
    }

    /** La contraseña estaba bien y falló otra cosa: ese intento no cuenta. */
    public static function devolver(string $clave): void
    {
        RateLimiter::decrement($clave, self::SEGUNDOS);
    }

    /** Acertó: se borran los fallos. */
    public static function limpiar(string $clave): void
    {
        RateLimiter::clear($clave);
    }
}
