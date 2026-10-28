<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Reglas de contraseña centralizadas, según config/security.php.
 */
class PasswordRules
{
    public static function reglas(): Password
    {
        $regla = Password::min((int) config('security.password.min_length', 12));

        if (config('security.password.require_mixed_case', true)) {
            $regla->mixedCase();
        }
        if (config('security.password.require_numbers', true)) {
            $regla->numbers();
        }
        if (config('security.password.require_symbols', true)) {
            $regla->symbols();
        }

        return $regla;
    }

    /**
     * Una contraseña al azar que CUMPLE {@see reglas()}.
     *
     * `Str::password()` asegura una letra, un número y un símbolo, pero no una mayúscula y
     * una minúscula: de vez en cuando (≈1 de cada 1000 con 24 caracteres) todas sus letras
     * salen del mismo caso y la regla de mayúsculas y minúsculas la rechaza. Se valida contra
     * las mismas reglas y se vuelve a sortear; repetir más de un par de veces es improbable.
     */
    public static function generar(int $largo): string
    {
        $largo = max($largo, (int) config('security.password.min_length', 12));

        for ($intento = 0; $intento < 100; $intento++) {
            $password = Str::password($largo);
            if (Validator::make(['password' => $password], ['password' => [self::reglas()]])->passes()) {
                return $password;
            }
        }

        throw new RuntimeException('No se pudo generar una contraseña que cumpla las reglas de config/security.php.');
    }
}
