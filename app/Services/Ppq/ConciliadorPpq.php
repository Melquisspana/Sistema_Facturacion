<?php

namespace App\Services\Ppq;

use App\Models\PpqItem;
use App\Models\PpqLote;
use Illuminate\Support\Facades\DB;

/**
 * Concilia un lote PPQ contra el TXT de pagos de Calleja (ya parseado por ConciliacionTxtParser).
 *
 * Regla central: un CCF NO está pagado por estar en el PPQ. Solo se marca PAGADO/CONCILIADO
 * cuando aparece en el TXT como tipo CF (y una NC como APLICADA cuando aparece como NC). El
 * cruce es por número de documento NORMALIZADO (sin guiones/espacios, mayúsculas) y por tipo.
 *
 * PERSISTE el estado de pago en cada item (conciliacion_estado/fecha_pago/monto_pagado) y
 * devuelve un resumen interno para mostrar en pantalla. NO toca el Excel oficial de Calleja.
 */
class ConciliadorPpq
{
    /**
     * @param  array<int, array<string, mixed>>  $filas  salida de ConciliacionTxtParser::parse()
     */
    public function conciliar(PpqLote $lote, array $filas): array
    {
        $lote->loadMissing('items');

        // Índices del TXT por número normalizado, separados por tipo.
        $cf = [];   // CCF pagados
        $nc = [];   // NC aplicadas
        $qd = [];   // ajustes/descuentos PPQ
        foreach ($filas as $f) {
            match ($f['tipo']) {
                'CF' => $cf[$f['numeroNorm']] = $f,
                'NC' => $nc[$f['numeroNorm']] = $f,
                'QD' => $qd[] = $f,
                default => null, // tipos no esperados se ignoran (pero cuentan como "otros" abajo)
            };
        }

        $usadosCf = [];
        $usadosNc = [];
        $ccfPagados = [];
        $ccfPendientes = [];
        $ncAplicadas = [];
        $ncPendientes = [];

        DB::transaction(function () use ($lote, $cf, $nc, &$usadosCf, &$usadosNc, &$ccfPagados, &$ccfPendientes, &$ncAplicadas, &$ncPendientes) {
            foreach ($lote->itemsOrdenados() as $item) {
                $clave = $item->numeroNormalizado();

                if ($item->esNc()) {
                    $fila = $clave !== null ? ($nc[$clave] ?? null) : null;
                    if ($fila) {
                        $usadosNc[$clave] = true;
                        $this->marcar($item, 'aplicada', $fila);
                        $ncAplicadas[] = $this->detalle($item, $fila);
                    } else {
                        $this->marcar($item, null, null);
                        $ncPendientes[] = $item;
                    }

                    continue;
                }

                // CCF (tipo 03).
                $fila = $clave !== null ? ($cf[$clave] ?? null) : null;
                if ($fila) {
                    $usadosCf[$clave] = true;
                    $this->marcar($item, 'pagado', $fila);
                    $ccfPagados[] = $this->detalle($item, $fila);
                } else {
                    $this->marcar($item, null, null);
                    $ccfPendientes[] = $item;
                }
            }
        });

        // Documentos del TXT (CF/NC) que NO están agregados al PPQ.
        $noEnPpq = [];
        foreach ($cf as $k => $f) {
            if (! isset($usadosCf[$k])) {
                $noEnPpq[] = $f;
            }
        }
        foreach ($nc as $k => $f) {
            if (! isset($usadosNc[$k])) {
                $noEnPpq[] = $f;
            }
        }

        return [
            'ccfPagados' => $ccfPagados,
            'ccfPendientes' => $ccfPendientes,
            'ncAplicadas' => $ncAplicadas,
            'ncPendientes' => $ncPendientes,
            'noEnPpq' => $noEnPpq,
            'ajustesQd' => $qd,
            'totales' => $this->totales($cf, $nc, $qd, $ccfPagados, $ccfPendientes, $ncAplicadas, $ncPendientes),
        ];
    }

    /** Persiste el estado de conciliación del item (o lo deja pendiente si $estado es null). */
    private function marcar(PpqItem $item, ?string $estado, ?array $fila): void
    {
        $item->forceFill([
            'conciliacion_estado' => $estado,
            'fecha_pago' => $estado ? ($fila['fecha'] ?? null) : null,
            'monto_pagado' => $estado && $fila['valor'] !== null ? abs((float) $fila['valor']) : null,
            'conciliado_en' => $estado ? now() : null,
        ])->save();
    }

    /** Fila de detalle para el resumen: item + datos del TXT + diferencia de monto. */
    private function detalle(PpqItem $item, array $fila): array
    {
        $montoTxt = $fila['valor'] !== null ? abs((float) $fila['valor']) : null;

        return [
            'item' => $item,
            'linea' => $fila['linea'],
            'fecha' => $fila['fecha'],
            'monto_txt' => $montoTxt,
            'diferencia' => $montoTxt === null ? null : round((float) $item->monto_dte - $montoTxt, 2),
        ];
    }

    private function totales(array $cf, array $nc, array $qd, array $ccfPagados, array $ccfPendientes, array $ncAplicadas, array $ncPendientes): array
    {
        $sum = fn (array $filas) => round(array_sum(array_map(fn ($f) => (float) ($f['valor'] ?? 0), $filas)), 2);

        $totalCf = $sum(array_values($cf));         // CF del TXT (positivo)
        $totalNc = $sum(array_values($nc));         // NC del TXT (negativo)
        $totalQd = $sum($qd);                        // QD del TXT (negativo)

        return [
            'cantidad_ccf_pagados' => count($ccfPagados),
            'cantidad_ccf_pendientes' => count($ccfPendientes),
            'cantidad_nc_aplicadas' => count($ncAplicadas),
            'cantidad_nc_pendientes' => count($ncPendientes),
            'cantidad_no_en_ppq' => (count($cf) - count($ccfPagados)) + (count($nc) - count($ncAplicadas)),
            'cantidad_qd' => count($qd),
            'total_ccf_pagado' => $totalCf,
            'total_nc_descontado' => $totalNc,
            'total_qd' => $totalQd,
            'neto_final' => round($totalCf + $totalNc + $totalQd, 2),
        ];
    }
}
