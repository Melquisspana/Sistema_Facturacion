<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\SaldosGastos;

/**
 * En qué punto está cada línea de una planilla. Tres estados, y se distinguen a
 * propósito porque significan cosas distintas para quien mira:
 *
 *   PREPARADO  la planilla es borrador. No hay obligación, no hay nada que pagar y
 *              cambiar un importe no tiene consecuencias.
 *   PENDIENTE  confirmada: la obligación existe y no se ha pagado nada.
 *   PARCIAL    se pagó una parte. Es el estado que más se confunde con «pagado», así
 *              que lleva nombre propio y muestra cuánto falta.
 *   PAGADO     no queda saldo.
 *
 * Los importes no se guardan acá: se leen del saldo REAL de Gastos, que es el único
 * sitio donde vive el dinero. Si se guardaran, habría dos verdades sobre lo mismo.
 */
final class EstadoPlanilla
{
    public const ETIQUETAS = [
        'preparado' => 'Preparado',
        'pendiente' => 'Pendiente de pago',
        'parcial' => 'Pagado en parte',
        'pagado' => 'Pagado',
        'sin_obligacion' => 'Sin nada que pagar',
    ];

    public function __construct(private SaldosGastos $saldos) {}

    /**
     * @return array{estado: string, a_pagar: int, pagado: int, pendiente: int}
     */
    public function deDetalle(PlanillaDetalle $detalle): array
    {
        $aPagar = Dinero::centavos((string) $detalle->a_pagar);

        if ($detalle->planilla->borrador()) {
            return ['estado' => 'preparado', 'a_pagar' => $aPagar, 'pagado' => 0, 'pendiente' => $aPagar];
        }

        if ($detalle->gasto_id === null) {
            // Confirmada pero sin obligación: todo se fue en descuentos.
            return ['estado' => 'sin_obligacion', 'a_pagar' => $aPagar, 'pagado' => 0, 'pendiente' => 0];
        }

        return $this->deGasto($detalle->gasto, $aPagar);
    }

    /** @return array{estado: string, a_pagar: int, pagado: int, pendiente: int} */
    public function deTercero(PlanillaObligacionTercero $obligacion): array
    {
        return $this->deGasto($obligacion->gasto, Dinero::centavos((string) $obligacion->importe));
    }

    /**
     * Resumen de la planilla entera: cuánto se pagó y cuánto falta, por los dos
     * caminos —empleados y terceros— que NO se suman con el total de ingresos.
     *
     * @return array<string, int>
     */
    public function dePlanilla(Planilla $planilla): array
    {
        $r = ['empleados_a_pagar' => 0, 'empleados_pagado' => 0, 'empleados_pendiente' => 0,
            'terceros_a_pagar' => 0, 'terceros_pagado' => 0, 'terceros_pendiente' => 0,
            'personas_pagadas' => 0, 'personas' => 0];

        foreach ($planilla->detalles as $detalle) {
            $e = $this->deDetalle($detalle);
            $r['empleados_a_pagar'] += $e['a_pagar'];
            $r['empleados_pagado'] += $e['pagado'];
            $r['empleados_pendiente'] += $e['pendiente'];
            $r['personas']++;

            if ($e['estado'] === 'pagado' || $e['estado'] === 'sin_obligacion') {
                $r['personas_pagadas']++;
            }
        }

        foreach (PlanillaObligacionTercero::where('planilla_id', $planilla->id)->with('gasto')->get() as $obligacion) {
            $e = $this->deTercero($obligacion);
            $r['terceros_a_pagar'] += $e['a_pagar'];
            $r['terceros_pagado'] += $e['pagado'];
            $r['terceros_pendiente'] += $e['pendiente'];
        }

        return $r;
    }

    /** @return array{estado: string, a_pagar: int, pagado: int, pendiente: int} */
    private function deGasto(?object $gasto, int $aPagar): array
    {
        if ($gasto === null) {
            return ['estado' => 'sin_obligacion', 'a_pagar' => $aPagar, 'pagado' => 0, 'pendiente' => 0];
        }

        $pagado = 0;
        $pendiente = 0;

        foreach ($gasto->cuotas as $cuota) {
            $pagado += $this->saldos->aplicado($cuota->id);
            $pendiente += max($this->saldos->pendienteCuota($cuota), 0);
        }

        $estado = match (true) {
            $pendiente <= 0 => 'pagado',
            $pagado > 0 => 'parcial',
            default => 'pendiente',
        };

        return ['estado' => $estado, 'a_pagar' => $aPagar, 'pagado' => $pagado, 'pendiente' => $pendiente];
    }
}
