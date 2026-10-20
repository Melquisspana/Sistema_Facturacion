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
     * Devuelve null si el documento no es FEX o no tiene cliente. En un documento emitido,
     * los datos fiscales salen del JSON transmitido ({@see DatosFiscalesEmision}).
     *
     * @return array{nombre: string, destino: ?string, documento: ?string, actividad: ?string, correo: ?string, direccion: ?string, telefono: ?string}|null
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

        $datos = [
            'nombre' => $cliente->nombre,
            'destino' => $cliente->pais?->nombre,
            'documento' => $cliente->num_documento,
            'actividad' => $cliente->actividadEconomica?->nombre,
            'correo' => $cliente->correo,
            'direccion' => $direccion !== '' ? $direccion : null,
            'telefono' => $cliente->telefono,
        ];
        $fiscales = DatosFiscalesEmision::desde($dte);
        if ($fiscales !== null) {
            $r = $fiscales['receptor'] ?? [];
            foreach (['nombre' => 'nombre', 'documento' => 'numDocumento', 'correo' => 'correo', 'telefono' => 'telefono'] as $campo => $clave) {
                $datos[$campo] = DatosFiscalesEmision::valor($datos[$campo], $r[$clave] ?? null, $campo === 'documento' && in_array($r['tipoDocumento'] ?? null, ['36', '13'], true));
            }
            $datos['actividad'] = DatosFiscalesEmision::actividad($cliente, $r, $datos['actividad']);
            if ((string) $cliente->pais?->codigo !== (string) ($r['codPais'] ?? null)) {
                $datos['destino'] = $r['nombrePais'] ?? $r['codPais'] ?? null;
            }
            if ((string) $ubicacion->direccion !== (string) ($r['complemento'] ?? null)) {
                $datos['direccion'] = $r['complemento'] ?? null;
            }
        }

        return $datos;
    }
}
