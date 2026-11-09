<?php

namespace App\Policies;

use App\Enums\PermisoSistema;
use App\Models\PedidoSalaEnvio;
use App\Models\User;
use App\Support\HoraNegocio;

/**
 * Envíos de la fábrica. Deshacer (anular) solo el envío del día, vigente y de un pedido que
 * no esté anulado. Un envío de un día anterior no se toca: se manda otro.
 */
class PedidoSalaEnvioPolicy
{
    public function anular(User $usuario, PedidoSalaEnvio $envio): bool
    {
        return $usuario->can(PermisoSistema::FabricaPedidosMandar->value)
            && $envio->estaVigente()
            && $envio->fecha->toDateString() === HoraNegocio::fechaHoy()
            && $envio->pedido->estaVigente();
    }
}
