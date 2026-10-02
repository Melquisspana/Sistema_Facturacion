<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;

/**
 * Bitácora de accesos (log `acceso` de activitylog): inicios y cierres de sesión,
 * intentos fallidos, bloqueos por exceso de intentos y restablecimientos de contraseña.
 *
 * Antes solo quedaba registrado el ingreso por Cloudflare Access: un ataque de fuerza
 * bruta o un acceso indebido por la red local no dejaban rastro.
 *
 * NUNCA se guarda la contraseña ni ningún otro dato de las credenciales que no sea el
 * correo con que se intentó entrar.
 */
class AuditoriaAccesos
{
    public function login(Login $evento): void
    {
        $this->registrar('Inicio de sesión', $evento->user instanceof User ? $evento->user : null, [
            'recordarme' => $evento->remember,
        ]);
    }

    public function logout(Logout $evento): void
    {
        $this->registrar('Cierre de sesión', $evento->user instanceof User ? $evento->user : null);
    }

    public function fallido(Failed $evento): void
    {
        $this->registrar('Intento de inicio de sesión fallido', $evento->user instanceof User ? $evento->user : null, [
            'email' => Str::limit((string) ($evento->credentials['email'] ?? ''), 191, ''),
        ]);
    }

    public function bloqueo(Lockout $evento): void
    {
        $this->registrar('Bloqueo por exceso de intentos de inicio de sesión', null, [
            'email' => Str::limit((string) $evento->request->input('email', ''), 191, ''),
        ]);
    }

    public function restablecida(PasswordReset $evento): void
    {
        $this->registrar('Contraseña restablecida por correo', $evento->user instanceof User ? $evento->user : null);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $eventos): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'fallido',
            Lockout::class => 'bloqueo',
            PasswordReset::class => 'restablecida',
        ];
    }

    /** @param  array<string, mixed>  $datos */
    private function registrar(string $descripcion, ?User $usuario, array $datos = []): void
    {
        $peticion = request();

        $actividad = activity('acceso')->withProperties($datos + [
            'ip' => $peticion->ip(),
            'agente' => Str::limit((string) $peticion->userAgent(), 255, ''),
        ]);

        if ($usuario !== null) {
            $actividad->causedBy($usuario)->performedOn($usuario);
        }

        $actividad->log($descripcion);
    }
}
