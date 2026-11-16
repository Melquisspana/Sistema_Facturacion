<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\EstadoPedidoWeb;
use App\Models\PedidoWeb;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use Illuminate\Support\Carbon;

/**
 * Pedidos de la tienda web que no se facturaron solos (ADR 0011): no cumplieron un
 * candado, Hacienda rechazó la factura o la factura lleva más de media hora sin salir
 * (sigue «facturando»). El aviso nombra solo los códigos de pedido: ni
 * el cliente, ni montos, ni el motivo, que queda guardado en el pedido.
 */
class PedidosWebParaRevisar implements Detector
{
    private const NOMBRADOS = 3;

    public function tipo(): string
    {
        return 'pedidos_web_para_revisar';
    }

    public function nombre(): string
    {
        return 'Pedidos web para revisar';
    }

    public function aplica(): bool
    {
        return true;
    }

    /** Una factura que sigue «facturando» pasado este tiempo está trabada (sin correlativo, firmador caído…). */
    public const MINUTOS_TRABADO = 30;

    public function revisar(): ?Hallazgo
    {
        $pendientes = PedidoWeb::query()->where(fn ($q) => $q
            ->where('estado', EstadoPedidoWeb::ParaRevisar)
            ->orWhere(fn ($q) => $q->where('estado', EstadoPedidoWeb::Facturando)
                ->where('updated_at', '<', now()->subMinutes(self::MINUTOS_TRABADO))));
        $n = (clone $pendientes)->count();

        if ($n === 0) {
            return null;
        }

        $codigos = (clone $pendientes)->orderBy('id')->limit(self::NOMBRADOS)->pluck('codigo')->implode(', ');

        return new Hallazgo(
            titulo: $n === 1 ? '1 pedido web no se facturó solo' : $n.' pedidos web no se facturaron solos',
            detalle: 'Para revisar: '.$codigos.($n > self::NOMBRADOS ? ' y '.($n - self::NOMBRADOS).' más.' : '.'),
            // La lista de pedidos web llega en la etapa 3; mientras tanto, por código.
            queHacer: 'Revisá el motivo de cada uno en la lista de pedidos web. Mientras esa pantalla no llegue, '
                .'la oficina lo busca con el código del pedido y decide si emite la factura a mano.',
            conteo: $n,
            desde: Carbon::parse($pendientes->min('updated_at')),
        );
    }
}
