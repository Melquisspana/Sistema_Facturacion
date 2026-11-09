<?php

namespace App\Policies;

use App\Enums\EstadoPedidoSala;
use App\Enums\PermisoSistema;
use App\Models\PedidoSala;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosSala;
use App\Support\HoraNegocio;

/**
 * Pedidos de sala. La oficina los mira con `rutas.pedidos.ver`. Corregir y anular: solo
 * quien lo sacó, el mismo día, mientras siga registrado, la fábrica no lo haya tocado y
 * pueda seguir sacando pedidos en esa salida (en curso y yendo en ella).
 *
 * La fábrica (`fabrica.pedidos.mandar`) manda mientras esté por mandar y reabre lo cerrado
 * que todavía se puede mandar. La regla de cada paso vive en {@see PedidosFabrica}.
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
            && ! $pedido->fabricaEmpezo()
            && $pedido->fecha->toDateString() === HoraNegocio::fechaHoy()
            && $pedido->salida !== null
            && $usuario->can('sacarPedido', $pedido->salida)
            && PedidosSala::loSaco($usuario, $pedido);
    }

    /**
     * Agregar o corregir la OC desde el detalle: la oficina (`rutas.gestionar`, que no tienen
     * ni el vendedor ni el jefe de vendedores), mientras el pedido no esté anulado: la OC
     * suele llegar por EDI después de que la fábrica lo mandó.
     */
    public function cambiarOrdenCompra(User $usuario, PedidoSala $pedido): bool
    {
        return $this->view($usuario, $pedido)
            && $usuario->can(PermisoSistema::RutasGestionar->value)
            && $pedido->estaVigente();
    }

    /** Mandar todo o una parte, armar la hoja y «No se trae». */
    public function mandar(User $usuario, PedidoSala $pedido): bool
    {
        return $usuario->can(PermisoSistema::FabricaPedidosMandar->value) && $pedido->estaPorMandar();
    }

    /**
     * Volver a pendiente lo cerrado que todavía se puede mandar: lo que no se trae, y lo
     * mandado con faltantes. Un mandado completo no se reabre: se anula su envío.
     */
    public function reabrir(User $usuario, PedidoSala $pedido): bool
    {
        if (! $usuario->can(PermisoSistema::FabricaPedidosMandar->value)) {
            return false;
        }

        return $pedido->estado === EstadoPedidoSala::NoSeTrae
            || ($pedido->estado === EstadoPedidoSala::Enviado && $pedido->faltaAlgo());
    }
}
