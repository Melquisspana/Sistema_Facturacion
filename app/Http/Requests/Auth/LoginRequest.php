<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->registrarIntentoFallido();

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Un usuario inactivo no puede iniciar sesión. Recibe el MISMO mensaje que una
        // contraseña equivocada: uno distinto confirmaría que la contraseña era correcta.
        if (! Auth::user()->activo) {
            Auth::logout();
            $this->registrarIntentoFallido();

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Solo se limpia el contador de la cuenta: el de la IP sigue, para que entrar con
        // una cuenta propia no reinicie el cupo con que se prueban las ajenas.
        RateLimiter::clear($this->throttleKey());
    }

    /** Cuenta el fallo para la cuenta (correo + IP) y para la IP sola. */
    private function registrarIntentoFallido(): void
    {
        $decaimiento = $this->decaimientoSegundos();

        RateLimiter::hit($this->throttleKey(), $decaimiento);
        RateLimiter::hit($this->throttleKeyIp(), $decaimiento);
    }

    private function decaimientoSegundos(): int
    {
        return max(1, (int) config('security.login_throttle.decay_minutes', 1)) * 60;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $porCuenta = max(1, (int) config('security.login_throttle.max_attempts', 5));
        $porIp = max($porCuenta, (int) config('security.login_throttle.max_attempts_por_ip', 20));

        $clave = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), $porCuenta) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->throttleKeyIp(), $porIp) => $this->throttleKeyIp(),
            default => null,
        };

        if ($clave === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($clave);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * Techo por IP, aparte del de cada cuenta: sin él, probar UNA contraseña contra
     * muchas cuentas desde la misma máquina nunca tocaba el límite.
     */
    public function throttleKeyIp(): string
    {
        return 'login-ip|'.$this->ip();
    }
}
