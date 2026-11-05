<?php

namespace App\Services\Rutas;

use Carbon\CarbonImmutable;

/**
 * Qué suele llevar una sala y cuánto, calculado por {@see EstimadoPedidos} con sus últimos
 * pedidos (etapa 4 de docs/PLAN_TABLERO_RUTAS.md). Es una sugerencia: el vendedor la
 * corrige en la sala y nunca se manda sola.
 *
 * Cada línea:
 *  · `producto_id`;
 *  · `cantidad`: la mediana de lo que llevó las veces que apareció, con los 4 decimales
 *    de la línea del documento ('12.0000');
 *  · `veces`: en cuántos de los últimos `pedidos` apareció;
 *  · `ultima_cantidad`: lo que llevó la última vez que apareció.
 */
final class PedidoEstimado
{
    public const CONFIABLE = Ritmo::CONFIABLE;

    public const APROXIMADO = Ritmo::APROXIMADO;

    public const POCO_DATO = 'poco_dato';

    /**
     * @param  array<int, array{producto_id: int, cantidad: string, veces: int, ultima_cantidad: string}>  $lineas
     * @param  int  $pedidos  cuántos pedidos se miraron (hasta 6)
     * @param  ?CarbonImmutable  $ultimo  fecha del último pedido; null si nunca pidió
     */
    public function __construct(
        public readonly array $lineas,
        public readonly int $pedidos,
        public readonly ?CarbonImmutable $ultimo,
    ) {}

    /** La misma idea que {@see Ritmo::confianza()}: 4 pedidos o más, confiable; 3, aproximado; menos, poco dato. */
    public function confianza(): string
    {
        return match (true) {
            $this->pedidos >= 4 => self::CONFIABLE,
            $this->pedidos === 3 => self::APROXIMADO,
            default => self::POCO_DATO,
        };
    }
}
