<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** Solo administrador gestiona usuarios. */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrador');
    }

    public function view(User $user, User $modelo): bool
    {
        return $user->hasRole('administrador');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('administrador');
    }

    public function update(User $user, User $modelo): bool
    {
        return $user->hasRole('administrador');
    }

    public function delete(User $user, User $modelo): bool
    {
        // Administrador, pero nunca a sí mismo.
        return $user->hasRole('administrador') && $user->id !== $modelo->id;
    }
}
