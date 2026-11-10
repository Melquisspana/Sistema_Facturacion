<?php

namespace App\Policies;

use App\Enums\PermisoSistema;
use App\Models\PedidoSalaEnvio;
use App\Models\User;
use App\Support\HoraNegocio;

/**
 * Envíos de la fábrica. Deshacer (anular) solo el envío del día, vigente, de un pedido que
 * no esté anulado y que la oficina todavía no facturó. Un envío de un día anterior no se
 * toca: se manda otro. Con las filas bloqueadas lo vuelve a revisar PedidosFabrica.
 */
class PedidoSalaEnvioPolicy
{
    public function anular(User $usuario, PedidoSalaEnvio $envio): bool
    {
        return $usuario->can(PermisoSistema::FabricaPedidosMandar->value)
            && $envio->estaVigente()
            && $envio->fecha->toDateString() === HoraNegocio::fechaHoy()
            && $envio->pedido->estaVigente()
            && ! $envio->estaFacturado();
    }
}
