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
    public static function estado(int|float|string|null $montoDte, int|float|string|null $montoAlbaran, bool $tieneAlbaran = false): array
    {
        if ($montoAlbaran === null || $montoAlbaran === '') {
            // Hay un albarán vinculado pero sin monto capturado: NO es "sin albarán".
            if ($tieneAlbaran) {
                return ['key' => 'albaran_sin_monto', 'label' => 'Albarán sin monto', 'clase' => 'bg-amber-100 text-amber-700'];
            }

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
     * ¿El albarán parece ser de OTRA sala que la del documento? Compara la sala de la OC
     * del documento con la sala embebida en el número de albarán (2º segmento). Es un aviso
     * SEPARADO de la diferencia de monto (no es "posible NC"): puede ser albarán equivocado.
     *
     * @return array{sala_doc: string, sala_albaran: string, label: string, detalle: string, clase: string}|null
     */
    public static function salaMismatch(?string $salaDoc, ?string $salaAlbaran): ?array
    {
        $doc = \App\Support\Sala::normalizar($salaDoc);
        $alb = \App\Support\Sala::normalizar($salaAlbaran);
        if ($doc === null || $alb === null || $doc === $alb) {
            return null;
        }

        return [
            'sala_doc' => $doc,
            'sala_albaran' => $alb,
            'label' => 'Albarán de otra sala',
            'detalle' => "El albarán parece ser de la sala {$alb}, pero el documento es de la sala {$doc}. Revisá si es el albarán correcto.",
            'clase' => 'bg-orange-100 text-orange-700',
        ];
    }

    /**
     * Estado de conciliación a nivel de LOTE, a partir de los conteos de items.
     * Verde si todo cuadra; ámbar si solo faltan albaranes; rojo si hay diferencias
     * de monto (posible NC/devolución). Incluye el motivo para el tooltip de alerta.
     *
     * @return array{key: string, clase: string, badge: string, motivo: string, alerta: bool}
     */
    public static function estadoLote(int $sinAlbaran, int $conDiferencia, int $albaranSinMonto = 0, int $otraSala = 0): array
    {
        $motivos = [];
        if ($conDiferencia > 0) {
            $motivos[] = $conDiferencia.' con diferencia de monto (posible NC/devolución)';
        }
        if ($otraSala > 0) {
            $motivos[] = $otraSala.' con albarán de otra sala';
        }
        if ($sinAlbaran > 0) {
            $motivos[] = $sinAlbaran.' sin albarán';
        }
        if ($albaranSinMonto > 0) {
            $motivos[] = $albaranSinMonto.' con albarán sin monto';
        }

        if ($motivos === []) {
            return ['key' => 'cuadra', 'clase' => 'text-green-700', 'badge' => 'bg-green-100 text-green-700', 'motivo' => 'Todos los documentos cuadran con su albarán.', 'alerta' => false];
        }

        $rojo = $conDiferencia > 0 || $otraSala > 0;

        return [
            'key' => $rojo ? 'diferencia' : 'incompleto',
            'clase' => $rojo ? 'text-red-700' : 'text-amber-700',
            'badge' => $rojo ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700',
            'motivo' => implode(' · ', $motivos),
            'alerta' => true,
        ];
    }
}
