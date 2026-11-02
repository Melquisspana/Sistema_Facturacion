<?php

namespace App\Services\Usuarios;

use App\Models\User;

class UltimoAdministrador
{
    public static function es(User $usuario): bool
    {
        if (! $usuario->activo || ! $usuario->hasRole('administrador')) {
            return false;
        }

        return ! User::role('administrador')->where('activo', true)->where('id', '!=', $usuario->id)->exists();
    }
}
