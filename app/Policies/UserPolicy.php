<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** La gestión de usuarios la controla el permiso usuarios.gestionar (solo admin). */
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function view(User $user, User $modelo): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function create(User $user): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function update(User $user, User $modelo): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function delete(User $user, User $modelo): bool
    {
        // Con permiso de gestión, pero nunca a sí mismo (guarda de método intacta).
        return $user->can('usuarios.gestionar') && $user->id !== $modelo->id;
    }
}
