<?php

namespace App\Policies;

use App\Enums\EstadoPedidoSala;
use App\Enums\OrigenPedidoSala;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\PedidoSala;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosOficina;
use App\Services\Rutas\PedidosSala;

/**
 * Pedidos de sala. La oficina los mira con `rutas.pedidos.ver`. Sacar pedido: quien tiene
 * `rutas.pedidos.registrar` y su ficha de Rutas activa, en cualquier sala, sin salida armada
 * (10/11/2026): el pedido se suma solo a su viaje. Corregir y anular: solo quien lo sacó,
 * mientras siga registrado, la fábrica no lo haya tocado y no haya sacado el pedido de la
 * sala siguiente (Melqui, 10/11/2026). Después lo corrige la oficina.
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
            && ! ($pedido->salida?->estado->esTerminal() ?? false)
            && $this->create($usuario)
            && PedidosSala::loSaco($usuario, $pedido)
            && ! PedidosSala::sacoElSiguiente($pedido);
    }

    /**
     * La oficina corrige (la sala y lo que falta mandar) o anula el pedido, con motivo
     * (Melqui, 13/11/2026): Facturación (`dte.gestionar`), el jefe de vendedores (el único que
     * además de ver todos los pedidos los saca: `rutas.pedidos.registrar`) y el administrador.
     * No Jefatura: desde el 26/11/2026 saca sus pedidos, pero los de la oficina no los corrige
     * ni los anula. Tampoco el vendedor ni la PC de pedidos: desde el 28/11/2026 el vendedor
     * tiene `rutas.pedidos.ver` para «Clientes», pero no la oficina (`rutas.ver`).
     * Que no esté facturado, lo ya mandado y la sala nueva los revisa {@see PedidosOficina},
     * con mensaje claro en vez de un 403. Lo que volvió a la fábrica (27/11/2026) ya no se
     * corrige ni se anula: queda cerrado así y, si la sala lo quiere, se saca otro pedido.
     */
    public function corregirEnOficina(User $usuario, PedidoSala $pedido): bool
    {
        return $this->view($usuario, $pedido)
            && $usuario->can(PermisoSistema::RutasVer->value)
            && ($usuario->can(PermisoSistema::DteGestionar->value)
                || ($usuario->can(PermisoSistema::RutasPedidosRegistrar->value) && ! $usuario->hasRole(RolSistema::Jefatura->value)))
            && $pedido->estaVigente()
            && $pedido->estado !== EstadoPedidoSala::Volvio;
    }

    /**
     * «Volvió a la fábrica» (27/11/2026), desde «Llegó de pedidos»: los mismos que facturan
     * ahí (ven el pedido y tienen `dte.gestionar`, como «Facturar»). Que se pueda (de sala,
     * con algo que salió y nada en un CCF) lo dice {@see PedidosFabrica::porQueNoVuelve()},
     * con mensaje claro en vez de un 403.
     */
    public function volverALaFabrica(User $usuario, PedidoSala $pedido): bool
    {
        return $this->view($usuario, $pedido) && $usuario->can(PermisoSistema::DteGestionar->value);
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

    /**
     * Enlazar la tienda nueva a su sala (10/11/2026): quien factura (`dte.gestionar`) y ve
     * el pedido. Que sea una tienda nueva vigente lo revisa {@see PedidosSala::enlazarSala()},
     * con un mensaje claro en vez de un 403.
     */
    public function enlazarSala(User $usuario, PedidoSala $pedido): bool
    {
        return $pedido->origen === OrigenPedidoSala::Sala
            && $this->view($usuario, $pedido) && $usuario->can(PermisoSistema::DteGestionar->value);
    }

    public function imprimirWeb(User $usuario, PedidoSala $pedido): bool
    {
        return $usuario->can(PermisoSistema::FabricaPedidosMandar->value) && $pedido->estaVigente();
    }

    public function recoger(User $usuario, PedidoSala $pedido): bool
    {
        return $pedido->origen === OrigenPedidoSala::Web && $this->imprimirWeb($usuario, $pedido);
    }

    /** Mandar todo o una parte, armar la hoja y «No se trae». */
    public function mandar(User $usuario, PedidoSala $pedido): bool
    {
        return $usuario->can(PermisoSistema::FabricaPedidosMandar->value) && $pedido->estaPorMandar();
    }

    /**
     * Volver a pendiente lo cerrado que todavía se puede mandar: lo que no se trae, y lo
     * mandado con faltantes. Un mandado completo no se reabre: se anula su envío. Lo que volvió
     * a la fábrica tampoco: se saca otro pedido.
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
