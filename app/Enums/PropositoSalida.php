<?php

namespace App\Enums;

/**
 * Para qué sale una salida de ruta (docs/PLAN_TABLERO_RUTAS.md, etapa 3). Ya nadie lo
 * elige: distingue por dentro el viaje (`ambos`: saca pedido y entrega) de la salida que se
 * arma sola al marcar una entrega (`entregar`). `pedido` quedó en salidas de antes.
 */
enum PropositoSalida: string
{
    case Entregar = 'entregar';
    case Pedido = 'pedido';
    case Ambos = 'ambos';

    /** ¿Lleva CCF para entregar? */
    public function entrega(): bool
    {
        return $this !== self::Pedido;
    }

    /** ¿Se saca pedido en las salas? */
    public function sacaPedido(): bool
    {
        return $this !== self::Entregar;
    }
}
