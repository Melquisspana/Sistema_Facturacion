<?php

namespace App\Services\TiendaWeb;

use App\Models\PedidoWeb;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

/** Contrato de avisos; guarda solo el tipo y HTTP, nunca el cuerpo remoto. */
class RespuestaAvisoPedidoWeb
{
    public static function estadoAceptado(Response $respuesta, string $estado): bool
    {
        $orden = ['preparando' => 0, 'en_ruta' => 1, 'lista' => 1, 'entregado' => 2];
        $actual = $respuesta->json('actual');

        return $respuesta->status() === 200 || ($respuesta->status() === 409
            && $respuesta->json('error') === 'estado' && is_string($actual)
            && isset($orden[$actual], $orden[$estado]) && $orden[$actual] >= $orden[$estado]);
    }

    public static function rechazado(PedidoWeb $pedido, string $aviso): bool
    {
        return str_contains((string) $pedido->motivo_revision, 'La página rechazó el aviso: '.$aviso.' (');
    }

    public static function anotar(PedidoWeb $pedido, Response $respuesta, string $aviso): void
    {
        if (! in_array($respuesta->status(), [404, 409, 422], true)) {
            return;
        }
        DB::transaction(function () use ($pedido, $respuesta, $aviso) {
            $fila = PedidoWeb::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            if (self::rechazado($fila, $aviso)) {
                return;
            }
            $fila->update(['motivo_revision' => trim((string) $fila->motivo_revision).(filled($fila->motivo_revision) ? "\n" : '')
                .'La página rechazó el aviso: '.$aviso.' (HTTP '.$respuesta->status().'). Revisalo en la página.']);
            activity('pedido_web')->performedOn($fila)->causedByAnonymous()
                ->withProperties(['aviso' => $aviso, 'http' => $respuesta->status()])
                ->log('aviso de pedido web rechazado');
        });
        $pedido->refresh();
    }
}
