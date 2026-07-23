<?php

namespace App\Policies;

use App\Models\Producto;
use App\Models\User;

class ProductoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('productos.ver');
    }

    public function view(User $user, Producto $producto): bool
    {
        return $user->can('productos.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('productos.gestionar');
    }

    public function update(User $user, Producto $producto): bool
    {
        return $user->can('productos.gestionar');
    }

    public function delete(User $user, Producto $producto): bool
    {
        return $user->can('productos.gestionar');
    }
}
