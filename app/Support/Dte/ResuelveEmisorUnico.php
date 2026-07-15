<?php

namespace App\Support\Dte;

use App\Models\Establecimiento;
use App\Models\PuntoVenta;

/**
 * Regla ÚNICA de dominio: si el formulario no envía establecimiento_id/punto_venta_id
 * del EMISOR y existe EXACTAMENTE una opción activa válida, la resuelve automáticamente
 * (evita pedir un select cuando no hay nada real que elegir). Si hay más de una opción
 * y no se envió valor, NO resuelve nada: la validación normal (`required`) sigue
 * exigiéndolo, porque ahí sí hay una ambigüedad real que el usuario debe resolver.
 *
 * Fuente de verdad reutilizada tanto por el FormRequest (CrearBorradorRequest) como por
 * el controller de Nota de Crédito independiente, para que backend y UI nunca discrepen.
 * Solo lectura: no crea ni modifica establecimientos/puntos de venta.
 */
final class ResuelveEmisorUnico
{
    /**
     * @return array{establecimiento_id: mixed, punto_venta_id: mixed}
     */
    public static function resolver(mixed $establecimientoId, mixed $puntoVentaId): array
    {
        if (blank($establecimientoId)) {
            $establecimientos = Establecimiento::where('activo', true)->get(['id']);
            if ($establecimientos->count() === 1) {
                $establecimientoId = $establecimientos->first()->id;
            }
        }

        if (blank($puntoVentaId)) {
            $query = PuntoVenta::where('activo', true);
            if (filled($establecimientoId)) {
                $query->where('establecimiento_id', $establecimientoId);
            }
            $puntosVenta = $query->get(['id']);
            if ($puntosVenta->count() === 1) {
                $puntoVentaId = $puntosVenta->first()->id;
            }
        }

        return ['establecimiento_id' => $establecimientoId, 'punto_venta_id' => $puntoVentaId];
    }
}
