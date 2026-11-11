<?php

namespace App\Policies;

use App\Enums\PermisoSistema;
use App\Models\Cliente;
use App\Models\PedidoSala;
use App\Models\User;
use App\Services\Rutas\PedidosSala;

class ClientePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clientes.ver');
    }

    public function view(User $user, Cliente $cliente): bool
    {
        return $user->can('clientes.ver');
    }

    /**
     * Dar de alta un cliente: quien gestiona clientes. Con `clientes.alta` (Facturación), solo
     * para la tienda nueva de un pedido: `$tiendaNueva` es el pedido vigente y sin sala que el
     * usuario puede enlazar ({@see PedidosSala::tiendaNuevaPorEnlazar()}); sin él no hay alta.
     */
    public function create(User $user, ?PedidoSala $tiendaNueva = null): bool
    {
        return $user->can(PermisoSistema::ClientesGestionar->value) || $this->altaDeTiendaNueva($user, $tiendaNueva);
    }

    /**
     * Dar de alta una sala del cliente: lo mismo que {@see create()}. Solo para el formulario
     * y el guardado del alta; editar, inactivar y borrar salas siguen pidiendo {@see update()}.
     */
    public function crearSala(User $user, Cliente $cliente, ?PedidoSala $tiendaNueva = null): bool
    {
        return $this->update($user, $cliente) || $this->altaDeTiendaNueva($user, $tiendaNueva);
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $user->can('clientes.gestionar');
    }

    public function delete(User $user, Cliente $cliente): bool
    {
        return $user->can('clientes.gestionar');
    }

    /** `clientes.alta` vale solo con una tienda nueva vigente, sin sala y que el usuario pueda enlazar. */
    private function altaDeTiendaNueva(User $user, ?PedidoSala $tiendaNueva): bool
    {
        return $tiendaNueva !== null
            && $user->can(PermisoSistema::ClientesAlta->value)
            && $tiendaNueva->estaVigente()
            && $tiendaNueva->esTiendaNueva()
            && $user->can('enlazarSala', $tiendaNueva);
    }
}
