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
 * Pedidos de sala. La oficina los mira con `rutas.pedidos.ver`. Sacar pedido: quien tiene
 * `rutas.pedidos.registrar` y su ficha de Rutas activa, en cualquier sala, sin salida armada
 * (10/11/2026): el pedido se suma solo a su viaje. Corregir y anular: solo quien lo sacó, el
 * mismo día, mientras siga registrado y la fábrica no lo haya tocado.
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

    /** Sacar pedido: el permiso y la ficha de Rutas activa enlazada al usuario. */
    public function create(User $usuario): bool
    {
        return $usuario->can(PermisoSistema::RutasPedidosRegistrar->value) && PedidosSala::persona($usuario) !== null;
    }

    public function corregir(User $usuario, PedidoSala $pedido): bool
    {
        return $pedido->estaRegistrado()
            && ! $pedido->fabricaEmpezo()
            && $pedido->fecha->toDateString() === HoraNegocio::fechaHoy()
            && ! ($pedido->salida?->estado->esTerminal() ?? false)
            && $this->create($usuario)
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
