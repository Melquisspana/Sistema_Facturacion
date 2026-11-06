<?php

namespace App\Policies;

use App\Enums\PermisoSistema;
use App\Models\PedidoSala;
use App\Models\User;
use App\Services\Rutas\PedidosSala;
use App\Support\HoraNegocio;

/**
 * Pedidos de sala. La oficina los mira con `rutas.pedidos.ver`. Corregir y anular: solo
 * quien lo sacó, el mismo día, mientras siga registrado y pueda seguir sacando pedidos en
 * esa salida (en curso y yendo en ella).
 */
class PedidoSalaPolicy
{
    public function view(User $usuario, PedidoSala $pedido): bool
    {
        return $usuario->can(PermisoSistema::RutasPedidosVer->value);
    }

    public function corregir(User $usuario, PedidoSala $pedido): bool
    {
        return $pedido->estaRegistrado()
            && $pedido->fecha->toDateString() === HoraNegocio::fechaHoy()
            && $pedido->salida !== null
            && $usuario->can('sacarPedido', $pedido->salida)
            && PedidosSala::loSaco($usuario, $pedido);
    }

    /**
     * Agregar o corregir la OC desde el detalle: la oficina (`rutas.gestionar`, que no tienen
     * ni el vendedor ni el jefe de vendedores), mientras el pedido siga registrado.
     */
    public function cambiarOrdenCompra(User $usuario, PedidoSala $pedido): bool
    {
        return $this->view($usuario, $pedido)
            && $usuario->can(PermisoSistema::RutasGestionar->value)
            && $pedido->estaRegistrado();
    }
}
