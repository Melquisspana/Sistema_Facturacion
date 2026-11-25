<?php

namespace App\Services\Alertas\Detectores;

use App\Models\PedidoWeb;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use Illuminate\Support\Carbon;

/**
 * Pedidos de la tienda web que no se facturaron solos (ADR 0011): no cumplieron un
 * candado, Hacienda rechazó la factura, la factura lleva más de media hora sin salir
 * (sigue «facturando») o el pedido pagado lleva más de media hora sin factura. Es la
 * misma regla de «Requieren atención» ({@see PedidoWeb::scopeRequiereAtencion()}), así
 * el botón lleva a una lista donde el pedido aparece arriba. El aviso nombra solo los
 * códigos de pedido: ni el cliente, ni montos, ni el motivo, que queda guardado en el pedido.
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
    public const MINUTOS_TRABADO = PedidoWeb::MINUTOS_TRABADO;

    public function revisar(): ?Hallazgo
    {
        $pendientes = PedidoWeb::query()->requiereAtencion();
        $n = (clone $pendientes)->count();

        if ($n === 0) {
            return null;
        }

        $codigos = (clone $pendientes)->orderBy('id')->limit(self::NOMBRADOS)->pluck('codigo')->implode(', ');

        return new Hallazgo(
            titulo: $n === 1 ? '1 pedido web requiere atención' : $n.' pedidos web requieren atención',
            detalle: 'Pedidos: '.$codigos.($n > self::NOMBRADOS ? ' y '.($n - self::NOMBRADOS).' más.' : '.'),
            queHacer: 'Revisá el motivo y las alertas de cada uno en la lista de pedidos web.',
            conteo: $n,
            desde: Carbon::parse($pendientes->min('updated_at')),
            enlace: 'facturacion.pedidos-web.index',
        );
    }
}
