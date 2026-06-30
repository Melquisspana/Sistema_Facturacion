<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\User;

class ClientePolicy
{
    /** Roles que pueden gestionar (crear, editar, activar/inactivar, eliminar). */
    private const GESTORES = ['administrador', 'facturacion'];

    /** Roles que pueden ver/listar. */
    private const LECTORES = ['administrador', 'facturacion', 'consulta', 'contador'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::LECTORES);
    }

    public function view(User $user, Cliente $cliente): bool
    {
        return $user->hasAnyRole(self::LECTORES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::GESTORES);
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $user->hasAnyRole(self::GESTORES);
    }

    public function delete(User $user, Cliente $cliente): bool
    {
        return $user->hasAnyRole(self::GESTORES);
    }
}
