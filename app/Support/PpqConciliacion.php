<?php

namespace App\Support;

/**
 * Clasificación de la conciliación entre el monto del CCF/NC y el del albarán.
 * Devuelve un estado legible y su clase de color, reutilizable en la búsqueda,
 * la vista del lote y cualquier otra pantalla del módulo PPQ.
 */
class PpqConciliacion
{
    /**
     * @return array{key: string, label: string, clase: string}
     */
    public static function estado(int|float|string|null $montoDte, int|float|string|null $montoAlbaran): array
    {
        if ($montoAlbaran === null || $montoAlbaran === '') {
            return ['key' => 'sin_albaran', 'label' => 'Sin albarán', 'clase' => 'bg-gray-100 text-gray-600'];
        }

        $dif = abs(round((float) $montoDte - (float) $montoAlbaran, 2));
        $coincide = (float) config('ppq.diferencia_coincide', 0.05);
        $pequena = (float) config('ppq.diferencia_pequena', 1.00);

        if ($dif <= $coincide) {
            return ['key' => 'coincide', 'label' => 'Monto coincide', 'clase' => 'bg-green-100 text-green-700'];
        }

        if ($dif <= $pequena) {
            return ['key' => 'pequena', 'label' => 'Diferencia pequeña', 'clase' => 'bg-amber-100 text-amber-700'];
        }

        return ['key' => 'posible_nc', 'label' => 'Posible NC/devolución', 'clase' => 'bg-red-100 text-red-700'];
    }

    /**
     * Estado de conciliación a nivel de LOTE, a partir de los conteos de items.
     * Verde si todo cuadra; ámbar si solo faltan albaranes; rojo si hay diferencias
     * de monto (posible NC/devolución). Incluye el motivo para el tooltip de alerta.
     *
     * @return array{key: string, clase: string, badge: string, motivo: string, alerta: bool}
     */
    public static function estadoLote(int $sinAlbaran, int $conDiferencia): array
    {
        $motivos = [];
        if ($conDiferencia > 0) {
            $motivos[] = $conDiferencia.' con diferencia de monto (posible NC/devolución)';
        }
        if ($sinAlbaran > 0) {
            $motivos[] = $sinAlbaran.' sin albarán';
        }

        if ($motivos === []) {
            return ['key' => 'cuadra', 'clase' => 'text-green-700', 'badge' => 'bg-green-100 text-green-700', 'motivo' => 'Todos los documentos cuadran con su albarán.', 'alerta' => false];
        }

        $rojo = $conDiferencia > 0;

        return [
            'key' => $rojo ? 'diferencia' : 'incompleto',
            'clase' => $rojo ? 'text-red-700' : 'text-amber-700',
            'badge' => $rojo ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700',
            'motivo' => implode(' · ', $motivos),
            'alerta' => true,
        ];
    }
}
