<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

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
}
