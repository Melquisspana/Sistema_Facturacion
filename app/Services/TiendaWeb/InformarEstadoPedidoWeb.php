<?php

namespace App\Services\TiendaWeb;

use App\Ajustes\Integraciones\ConfiguracionTiendaWeb;
use App\Models\PedidoWeb;
use Illuminate\Support\Facades\Cache;
use Throwable;

class InformarEstadoPedidoWeb
{
    public function __construct(private readonly ConfiguracionTiendaWeb $tienda) {}

    public function pasada(bool $aplicar): void
    {
        if (! $aplicar || ! $this->tienda->completo()) {
            return;
        }

        PedidoWeb::whereNotNull('estado_por_informar')->eachById(function (PedidoWeb $pedido) {
            Cache::lock('tienda-web-estado-'.$pedido->id, 60)->get(function () use ($pedido) {
                $pedido->refresh();
                if ($pedido->estado_por_informar === null || $pedido->lista_en === null || $pedido->informado_preparando_en === null) {
                    return;
                }
                // No saltear «lista» (o «en_ruta», el de ruta) si lo entregaron antes de la
                // siguiente pasada: la página no pasa de «preparando» a «entregado».
                if ($pedido->estado_por_informar === 'entregado'
                    && ($pedido->estado_informado_en === null || $pedido->estado_informado_en->lt($pedido->lista_en))) {
                    if (! $this->enviar($pedido, $pedido->entrega_tipo === 'ruta' ? 'en_ruta' : 'lista')) {
                        return;
                    }
                    // Conservar el avance si falla el POST de «entregado» a continuación.
                    PedidoWeb::whereKey($pedido->id)->where('estado_por_informar', 'entregado')
                        ->update(['estado_informado_en' => now()]);
                }
                if (! $this->enviar($pedido, $pedido->estado_por_informar)) {
                    return;
                }
                // Si fábrica cambió el estado durante el HTTP, no dar por avisado ese cambio.
                PedidoWeb::whereKey($pedido->id)->where('estado_por_informar', $pedido->estado_por_informar)
                    ->where('lista_en', $pedido->lista_en)
                    ->when($pedido->entregado_en, fn ($q) => $q->where('entregado_en', $pedido->entregado_en), fn ($q) => $q->whereNull('entregado_en'))
                    // Vaciar el pendiente también distingue dos cambios en el mismo segundo.
                    ->update(['estado_informado_en' => now(), 'estado_por_informar' => null]);
            });
        });
    }

    private function enviar(PedidoWeb $pedido, string $estado): bool
    {
        if (RespuestaAvisoPedidoWeb::rechazado($pedido, $estado)) {
            return false;
        }
        try {
            $respuesta = $this->tienda->cliente()->post($this->tienda->url().'/api/sistema/pedidos/'.$pedido->web_id.'/estado', ['estado' => $estado]);

            if (RespuestaAvisoPedidoWeb::estadoAceptado($respuesta, $estado)) {
                return true;
            }
            RespuestaAvisoPedidoWeb::anotar($pedido, $respuesta, $estado);

            return false;
        } catch (Throwable) {
            return false;
        }
    }
}
