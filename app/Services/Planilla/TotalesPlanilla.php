<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Services\Gastos\Dinero;

/**
 * La aritmética de la planilla. Todo en CENTAVOS ENTEROS, igual que en Gastos: ni un
 * `float` en el camino.
 *
 * ══════════════ Una resta, no cuatro totales sueltos ══════════════
 *
 *      TOTAL DE INGRESOS   salario del período + otros ingresos
 *    − DESCUENTOS          lo que se le resta
 *    ─────────────────────────────────────────────────────────────
 *    = A PAGAR             lo que se le entrega a la persona
 *
 * Y los descuentos se DESGLOSAN —no son otro total al lado— en tres cosas que
 * significan cosas distintas:
 *
 *    a terceros   la empresa se lo debe a alguien más → genera su obligación
 *    anticipos    ya se le pagó antes → no genera nada, pero guarda su referencia
 *    otros        cualquier otro descuento acordado
 *
 * De ahí que el gasto salarial sea el TOTAL DE INGRESOS y no la suma de las
 * obligaciones: lo que se paga al empleado y lo que se debe a terceros son PARTES de
 * ese total, no algo que se le agregue. Sumarlos contaría el mismo dinero dos veces,
 * que es el error caro de este módulo.
 *
 * `sin_clasificar` son los descuentos a los que todavía no se les dijo de cuál de los
 * tres casos se trata. Se cuentan aparte a propósito: no se asume que sean anticipos
 * —eso era justamente lo ambiguo— y bloquean la confirmación hasta que alguien lo
 * diga.
 *
 * NADA ACÁ ES UN CÁLCULO LEGAL. No hay ISSS, AFP, renta, vacaciones, aguinaldo,
 * indemnización ni horas extra: solo sumas y restas de importes escritos y revisados.
 */
final class TotalesPlanilla
{
    /** Las claves que devuelve, en el orden en que se leen. */
    public const CAMPOS = [
        'salario', 'otros_ingresos', 'total_ingresos',
        'descuentos', 'a_terceros', 'anticipos', 'otros_descuentos', 'sin_clasificar',
        'a_pagar',
    ];

    /**
     * Totales de UNA línea, en centavos.
     *
     * @param  array<int, array{tipo: string, importe: string|null, destino?: ?string, tercero?: ?string, referencia?: ?string}>  $conceptos
     * @return array<string, int>
     */
    public function linea(?string $salario, array $conceptos): array
    {
        $base = $this->centavos($salario);
        $otrosIngresos = 0;
        $aTerceros = 0;
        $anticipos = 0;
        $otrosDescuentos = 0;
        $sinClasificar = 0;

        foreach ($conceptos as $c) {
            $importe = $this->centavos($c['importe'] ?? null);

            if ($importe <= 0) {
                continue;
            }

            if (($c['tipo'] ?? null) === 'ingreso') {
                $otrosIngresos += $importe;

                continue;
            }

            match (true) {
                // Solo cuenta como deuda con un tercero si SE DIJO a quién. Sin nombre no
                // se puede crear una obligación con nadie.
                ($c['destino'] ?? null) === 'tercero' && filled($c['tercero'] ?? null) => $aTerceros += $importe,
                ($c['destino'] ?? null) === 'anticipo' => $anticipos += $importe,
                ($c['destino'] ?? null) === 'otro' => $otrosDescuentos += $importe,
                // Incluye el «tercero» sin nombre: está a medio declarar.
                default => $sinClasificar += $importe,
            };
        }

        $totalIngresos = $base + $otrosIngresos;
        $descuentos = $aTerceros + $anticipos + $otrosDescuentos + $sinClasificar;

        return [
            'salario' => $base,
            'otros_ingresos' => $otrosIngresos,
            'total_ingresos' => $totalIngresos,
            'descuentos' => $descuentos,
            'a_terceros' => $aTerceros,
            'anticipos' => $anticipos,
            'otros_descuentos' => $otrosDescuentos,
            'sin_clasificar' => $sinClasificar,
            'a_pagar' => $totalIngresos - $descuentos,
        ];
    }

    /** Los totales de una línea ya guardada. @return array<string, int> */
    public function deDetalle(PlanillaDetalle $detalle): array
    {
        return $this->linea(
            (string) $detalle->salario,
            $detalle->conceptos->map(fn ($c) => [
                'tipo' => $c->tipo, 'importe' => (string) $c->importe,
                'destino' => $c->destino, 'tercero' => $c->tercero, 'referencia' => $c->referencia,
            ])->all(),
        );
    }

    /**
     * Totales de la planilla entera, sumando línea por línea.
     *
     * @return array<string, int>
     */
    public function dePlanilla(Planilla $planilla): array
    {
        $total = array_fill_keys(self::CAMPOS, 0) + ['empleados' => 0, 'negativos' => 0];

        foreach ($planilla->detalles as $detalle) {
            $linea = $this->deDetalle($detalle);

            foreach (self::CAMPOS as $campo) {
                $total[$campo] += $linea[$campo];
            }

            $total['empleados']++;

            // Un «a pagar» negativo casi siempre es un error de captura —descuentos por
            // encima de los ingresos— y bloquea la confirmación. Se cuenta acá para
            // avisarlo mientras todavía es un borrador.
            if ($linea['a_pagar'] < 0) {
                $total['negativos']++;
            }
        }

        return $total;
    }

    /** Lo mismo, ya formateado para mostrar. @return array<string, string> */
    public function enDecimal(array $totales): array
    {
        $salida = [];

        foreach ($totales as $clave => $valor) {
            $salida[$clave] = in_array($clave, ['empleados', 'negativos'], true)
                ? (string) $valor
                : Dinero::decimal((int) $valor);
        }

        return $salida;
    }

    private function centavos(?string $valor): int
    {
        $valor = trim((string) $valor);

        return $valor === '' ? 0 : Dinero::centavos($valor);
    }
}
