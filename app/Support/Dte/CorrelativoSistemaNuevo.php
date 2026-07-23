<?php

namespace App\Support\Dte;

use App\Exceptions\Dte\PuntoVentaPredeterminadoInvalidoException;
use App\Models\Correlativo;

/**
 * Resuelve el correlativo del SISTEMA NUEVO (punto de venta predeterminado,
 * `dte.punto_venta_predeterminado` — hoy P002), NUNCA el de Conta Portable (P001).
 *
 * Reutiliza {@see ResuelveEmisorUnico} (misma regla de dominio que usa el formulario
 * de creación) para no duplicar cómo se resuelve el establecimiento/punto de venta.
 * Existe porque varias pantallas de readiness hacían
 * `Correlativo::where('tipo_dte', ...)->where('ambiente', ...)->first()` SIN filtrar
 * por punto de venta: con P001 y P002 como filas activas distintas para el mismo
 * tipo/ambiente, ese `first()` devolvía la fila que hubiera quedado primera en la
 * tabla (P001), no la del sistema nuevo. Ver `DteGeneracionService` para el mismo
 * filtro ya correcto en el motor real de emisión.
 *
 * Solo lectura: nunca crea, reserva ni modifica ningún correlativo.
 */
final class CorrelativoSistemaNuevo
{
    /**
     * @return array{establecimiento_id: ?int, punto_venta_id: ?int}
     */
    public static function establecimientoYPuntoVenta(): array
    {
        try {
            $r = ResuelveEmisorUnico::resolver(null, null);
        } catch (PuntoVentaPredeterminadoInvalidoException) {
            // El readiness nunca debe romperse por una configuración inválida: se
            // reporta como "no resuelto" (el llamador lo muestra como '—' / crítico),
            // no como un error 500.
            return ['establecimiento_id' => null, 'punto_venta_id' => null];
        }

        return [
            'establecimiento_id' => $r['establecimiento_id'] ?? null,
            'punto_venta_id' => $r['punto_venta_id'] ?? null,
        ];
    }

    /**
     * Misma regla de resolución que {@see \App\Services\Dte\DteGeneracionService}
     * (`resolverCorrelativo()`): prioriza la fila que coincide EXACTO con el punto de
     * venta del sistema nuevo, pero cae de vuelta a una fila con `punto_venta_id NULL`
     * (contador compartido del establecimiento) si no hay una específica — así el
     * readiness ve exactamente el mismo correlativo que usará la emisión real.
     */
    public static function correlativo(string $tipoDte, string $ambiente = '01'): ?Correlativo
    {
        $r = self::establecimientoYPuntoVenta();
        if (blank($r['establecimiento_id']) || blank($r['punto_venta_id'])) {
            return null;
        }

        return Correlativo::where('tipo_dte', $tipoDte)
            ->where('ambiente', $ambiente)
            ->where('establecimiento_id', $r['establecimiento_id'])
            ->where('activo', true)
            ->where(function ($q) use ($r) {
                $q->where('punto_venta_id', $r['punto_venta_id'])->orWhereNull('punto_venta_id');
            })
            ->orderByRaw('punto_venta_id IS NULL')
            ->first();
    }

    /** Null si no se pudo resolver el punto de venta o no hay correlativo activo. */
    public static function proximoNumero(string $tipoDte, string $ambiente = '01'): ?int
    {
        $corr = self::correlativo($tipoDte, $ambiente);

        return $corr === null ? null : $corr->ultimo_numero + 1;
    }
}
