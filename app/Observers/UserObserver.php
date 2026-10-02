<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Auth\RevocadorSesiones;
use Illuminate\Support\Facades\Auth;

/**
 * Revoca los accesos vivos cuando cambia algo que debería sacar al usuario.
 *
 * Va en el observer y no en cada controlador porque hay varios caminos que tocan estos
 * campos —panel de usuarios, perfil propio, restablecimiento por correo— y basta con
 * olvidar uno para que la sesión de alguien desactivado siga abierta.
 */
class UserObserver
{
    public function __construct(private readonly RevocadorSesiones $revocador) {}

    public function updated(User $usuario): void
    {
        if ($usuario->wasChanged('activo') && ! $usuario->activo) {
            $this->revocador->revocar($usuario);

            return;
        }

        if ($usuario->wasChanged('password')) {
            // Si quien cambia la contraseña es el propio usuario, conserva SU sesión: el
            // resto de los dispositivos queda fuera.
            $propia = Auth::check() && Auth::id() === $usuario->getKey() && request()->hasSession();

            $this->revocador->revocar($usuario, $propia ? request()->session()->getId() : null);
        }
    }
}
