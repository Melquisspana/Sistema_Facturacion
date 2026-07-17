<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Models\Dte;

/**
 * Resuelve los datos del RECEPTOR de una Factura de exportación (FEX, tipo 11)
 * para mostrar en editor/ficha/PDF/impresión: destino (país), documento,
 * actividad económica, correo, dirección y teléfono. Usa exactamente las mismas
 * fuentes que el serializador oficial (ver App\Services\Dte\MapeadorDteSalida::receptor()):
 * `cliente->correo`, `cliente->telefono`, `cliente->pais`, dirección de la sala
 * de entrega si existe (si no, la del cliente). Solo lectura/presentación: no
 * recalcula nada fiscal, no modifica el DTE.
 */
final class ReceptorExportacionPresentacion
{
    /**
     * @return array{nombre: string, destino: ?string, documento: ?string, actividad: ?string, correo: ?string, direccion: ?string, telefono: ?string}|null
     *               null si el documento no es FEX o no tiene cliente.
     */
    public static function resolver(Dte $dte): ?array
    {
        if ($dte->tipo_dte !== TipoDte::FacturaExportacion) {
            return null;
        }

        $cliente = $dte->cliente;
        if (! $cliente) {
            return null;
        }

        // Misma unidad de ubicación que el JSON oficial: la sala de entrega si el
        // DTE tiene una seleccionada; si no, el propio cliente.
        $ubicacion = $dte->clienteSucursal ?? $cliente;
        $direccion = trim((string) ($ubicacion->direccion ?? ''));
        if ($direccion !== '' && filled($cliente->complemento_direccion ?? null) && $ubicacion === $cliente) {
            $direccion .= ' — '.$cliente->complemento_direccion;
        }

        return [
            'nombre' => $cliente->nombre,
            'destino' => $cliente->pais?->nombre,
            'documento' => $cliente->num_documento,
            'actividad' => $cliente->actividadEconomica?->nombre,
            'correo' => $cliente->correo,
            'direccion' => $direccion !== '' ? $direccion : null,
            'telefono' => $cliente->telefono,
        ];
    }
}
