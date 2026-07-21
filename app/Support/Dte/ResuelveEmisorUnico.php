<?php

namespace App\Support\Dte;

use App\Exceptions\Dte\PuntoVentaPredeterminadoInvalidoException;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;

/**
 * Regla ÚNICA de dominio: si el formulario no envía establecimiento_id/punto_venta_id
 * del EMISOR, resuelve automáticamente el punto de venta a usar:
 *
 *  - Si hay un punto de venta PREDETERMINADO configurado (dte.punto_venta_predeterminado,
 *    por código — ej. "P002" para convivir con Conta Portable en "P001"), SIEMPRE se usa
 *    ese, sin importar cuántos puntos de venta activos existan. Nunca cae de vuelta a otro
 *    en silencio: si el código no existe, está inactivo, o no pertenece al establecimiento
 *    resuelto, lanza PuntoVentaPredeterminadoInvalidoException (mensaje claro).
 *  - Sin configuración: comportamiento anterior. Si existe EXACTAMENTE una opción activa
 *    válida, la resuelve automáticamente (evita pedir un select cuando no hay nada real que
 *    elegir). Si hay más de una opción, NO resuelve nada: la validación normal (`required`)
 *    sigue exigiéndolo, porque ahí sí hay una ambigüedad real que el usuario debe resolver.
 *
 * Fuente de verdad reutilizada tanto por el FormRequest (CrearBorradorRequest) como por
 * el controller de Nota de Crédito independiente y CrearFexDesdeExportacionService, para
 * que backend y UI nunca discrepen. Solo lectura: no crea ni modifica establecimientos ni
 * puntos de venta.
 */
final class ResuelveEmisorUnico
{
    /**
     * @return array{establecimiento_id: mixed, punto_venta_id: mixed}
     *
     * @throws PuntoVentaPredeterminadoInvalidoException
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
            $puntoVentaId = filled($establecimientoId)
                ? self::resolverPuntoVenta((int) $establecimientoId)?->id
                : self::puntoVentaUnicoActivo()?->id;
        }

        return ['establecimiento_id' => $establecimientoId, 'punto_venta_id' => $puntoVentaId];
    }

    /**
     * Punto de venta a usar de forma OCULTA (sin mostrarle un select al usuario) para un
     * establecimiento dado: el predeterminado configurado, o el único activo si no hay
     * configuración. Null si sigue siendo ambiguo (sin configuración y más de una opción
     * activa) — ahí sí debe mostrarse el select. Reutilizado por las vistas de creación
     * para decidir si ocultan el campo de punto de venta.
     *
     * @throws PuntoVentaPredeterminadoInvalidoException
     */
    public static function puntoVentaOculto(?int $establecimientoId): ?PuntoVenta
    {
        return $establecimientoId === null ? null : self::resolverPuntoVenta($establecimientoId);
    }

    /**
     * @throws PuntoVentaPredeterminadoInvalidoException
     */
    private static function resolverPuntoVenta(int $establecimientoId): ?PuntoVenta
    {
        $codigo = trim((string) config('dte.punto_venta_predeterminado', ''));

        if ($codigo !== '') {
            return self::resolverPorCodigo($establecimientoId, $codigo);
        }

        $puntosVenta = PuntoVenta::where('activo', true)->where('establecimiento_id', $establecimientoId)->get();

        return $puntosVenta->count() === 1 ? $puntosVenta->first() : null;
    }

    /**
     * @throws PuntoVentaPredeterminadoInvalidoException
     */
    private static function resolverPorCodigo(int $establecimientoId, string $codigo): PuntoVenta
    {
        $pv = PuntoVenta::where('establecimiento_id', $establecimientoId)->where('codigo', $codigo)->first();

        if (! $pv) {
            throw new PuntoVentaPredeterminadoInvalidoException(
                "El punto de venta predeterminado configurado (\"{$codigo}\") no existe para este establecimiento."
            );
        }
        if (! $pv->activo) {
            throw new PuntoVentaPredeterminadoInvalidoException(
                "El punto de venta predeterminado configurado (\"{$codigo}\") está inactivo."
            );
        }

        return $pv;
    }

    private static function puntoVentaUnicoActivo(): ?PuntoVenta
    {
        $puntosVenta = PuntoVenta::where('activo', true)->get();

        return $puntosVenta->count() === 1 ? $puntosVenta->first() : null;
    }
}
