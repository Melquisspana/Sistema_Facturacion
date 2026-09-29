<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Services\Gastos\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Las DOS preguntas que una planilla responde, y que no se suman entre sí.
 *
 * ═══════════ Por qué son dos informes y no uno ═══════════
 *
 * «¿Cuánto debemos?» y «¿cuánto salió?» parecen la misma pregunta y no lo son. Un
 * informe que las mezcle cuenta el mismo dinero dos veces, y el caso que lo destapa es
 * el anticipo:
 *
 *   Ingresos del período      100
 *   Anticipo ya entregado    − 40   ← este dinero SALIÓ ANTES, por su propio pago
 *   ───────────────────────────────
 *   Obligación con la persona  60   ← esto es lo único que la planilla debe hoy
 *
 * Lo desembolsado son **100**: los 40 del anticipo, que salieron la semana pasada, más
 * los 60 que se pagan ahora. Y las obligaciones de la empresa también son 100: el gasto
 * del anticipo (40, anterior y ya pagado) más la obligación de esta planilla (60).
 *
 * El error caro es sumar el TOTAL DE INGRESOS con el gasto del anticipo: da 140 y no
 * existe. Los 100 de ingresos YA CONTIENEN los 40. Por eso {@see obligaciones()}
 * devuelve `total_ingresos` marcado como informativo y **nunca** dentro del total.
 *
 * ═══════════ La categoría del anticipo da lo mismo ═══════════
 *
 * Si el gasto del anticipo se archivó como «Salarios» o como «Anticipos al personal»
 * cambia cómo se lee un informe por categoría, y nada más. El desembolso del período
 * son 100 en los dos casos, porque se cuenta el DINERO QUE SALIÓ —los pagos— y no la
 * etiqueta que alguien le puso. Hay una prueba dedicada exactamente a eso.
 *
 * Dicho lo cual, la recomendación sigue en pie: archivarlos como anticipos al personal,
 * para que un informe de egresos por categoría no muestre el sueldo partido en dos
 * filas que parecen sueldos distintos.
 *
 * ═══════════ De dónde salen las cifras ═══════════
 *
 * De los saldos REALES de Gastos, igual que {@see EstadoPlanilla}. Acá no se guarda ni
 * un importe: si se guardaran, habría dos verdades sobre el mismo dinero.
 */
final class InformePlanilla
{
    public function __construct(private EstadoPlanilla $estado) {}

    /**
     * INFORME DE OBLIGACIONES: qué comprometió ESTA planilla al confirmarse.
     *
     * `total` es solo lo que la planilla creó. Ni el total de ingresos ni los anticipos
     * entran ahí: los anticipos son obligaciones ANTERIORES, de Gastos, que esta
     * planilla no creó y que probablemente ya estaban pagadas. Se devuelven aparte
     * porque sirven para cuadrar, no para sumar.
     *
     * @return array<string, int>
     */
    public function obligaciones(Planilla $planilla): array
    {
        $avance = $this->estado->dePlanilla($planilla);

        return [
            'empleados' => $avance['empleados_a_pagar'],
            'terceros' => $avance['terceros_a_pagar'],
            'total' => $avance['empleados_a_pagar'] + $avance['terceros_a_pagar'],

            // Informativos. NO son obligaciones de esta planilla y no entran en `total`.
            'total_ingresos' => $this->totalIngresos($planilla),
            'anticipos_aplicados' => $this->anticiposAplicados($planilla),
        ];
    }

    /**
     * INFORME DE DESEMBOLSOS: cuánto dinero salió por esta planilla.
     *
     * Incluye los anticipos aplicados aunque su pago sea anterior y viva en su propio
     * gasto: para la pregunta «¿cuánto le costó al negocio este período?», ese dinero
     * salió por este período. Es justamente lo que un informe de pagos que solo mirara
     * las obligaciones de la planilla se perdería.
     *
     * @return array<string, int>
     */
    public function desembolsos(Planilla $planilla): array
    {
        $avance = $this->estado->dePlanilla($planilla);
        $anticipos = $this->anticiposAplicados($planilla);

        return [
            'a_empleados' => $avance['empleados_pagado'],
            'anticipos' => $anticipos,
            'a_terceros' => $avance['terceros_pagado'],
            'total' => $avance['empleados_pagado'] + $anticipos + $avance['terceros_pagado'],

            // Lo que todavía no ha salido, para que nadie lea «total» como «final».
            'pendiente' => $avance['empleados_pendiente'] + $avance['terceros_pendiente'],
        ];
    }

    /**
     * El cuadre, que es lo que convierte los dos informes anteriores en algo
     * comprobable en vez de dos listas de números que hay que creerse.
     *
     * La identidad, con la planilla PAGADA DEL TODO:
     *
     *   desembolsado + otros descuentos = total de ingresos
     *
     * «Otros descuentos» y los que están sin clasificar no son dinero que salga de la
     * empresa: se le restan a la persona y se quedan en casa. Por eso aparecen del lado
     * de los ingresos y no del desembolso.
     *
     * @return array{cuadra: bool, desembolsado: int, retenido: int, total_ingresos: int, diferencia: int}
     */
    public function cuadre(Planilla $planilla): array
    {
        $desembolsos = $this->desembolsos($planilla);
        $ingresos = $this->totalIngresos($planilla);
        $retenido = $this->retenidoEnCasa($planilla);

        // El pendiente entra en el cuadre: todavía no salió, pero está comprometido. Sin
        // él, una planilla a medio pagar «no cuadraría» y el aviso no significaría nada.
        $comprometido = $desembolsos['total'] + $desembolsos['pendiente'] + $retenido;

        return [
            'cuadra' => $comprometido === $ingresos,
            'desembolsado' => $desembolsos['total'],
            'retenido' => $retenido,
            'total_ingresos' => $ingresos,
            'diferencia' => $comprometido - $ingresos,
        ];
    }

    /** Suma de lo que la planilla declara como ingresos de todas las personas. */
    private function totalIngresos(Planilla $planilla): int
    {
        return $planilla->detalles->sum(fn ($d) => Dinero::centavos((string) $d->total_ingresos));
    }

    /**
     * Descuentos que NO salen de la empresa: los «otros» y los que aún no se
     * clasificaron. Se le restan a la persona y ahí se quedan.
     */
    private function retenidoEnCasa(Planilla $planilla): int
    {
        return $planilla->detalles->sum(function ($detalle) {
            $ingresos = Dinero::centavos((string) $detalle->total_ingresos);
            $aPagar = Dinero::centavos((string) $detalle->a_pagar);

            $aTerceros = $detalle->conceptos
                ->where('tipo', 'descuento')->where('destino', 'tercero')
                ->sum(fn ($c) => Dinero::centavos((string) $c->importe));

            $anticipos = $detalle->conceptos
                ->where('tipo', 'descuento')->where('destino', 'anticipo')
                ->sum(fn ($c) => Dinero::centavos((string) $c->importe));

            return $ingresos - $aPagar - $aTerceros - $anticipos;
        });
    }

    /**
     * Anticipos descontados en ESTA planilla, en centavos.
     *
     * Se leen de las APLICACIONES, no del texto del concepto: la aplicación es la fila
     * que impide recuperar el mismo anticipo dos veces, y por eso es la única cifra que
     * se puede defender.
     */
    private function anticiposAplicados(Planilla $planilla): int
    {
        return DB::table('planilla_anticipo_aplicaciones as ap')
            ->join('planilla_conceptos as c', 'c.id', '=', 'ap.planilla_concepto_id')
            ->join('planilla_detalles as d', 'd.id', '=', 'c.planilla_detalle_id')
            ->where('d.planilla_id', $planilla->id)
            ->pluck('ap.importe')
            ->sum(fn ($v) => Dinero::centavos((string) $v));
    }

    /**
     * Lo mismo, para UNA persona. Lo usa la pantalla para poder decir «cobró 100» en
     * vez de «cobró 60», que es cierto solo a medias.
     */
    public function anticiposDeDetalle(int $detalleId): int
    {
        return DB::table('planilla_anticipo_aplicaciones as ap')
            ->join('planilla_conceptos as c', 'c.id', '=', 'ap.planilla_concepto_id')
            ->where('c.planilla_detalle_id', $detalleId)
            ->pluck('ap.importe')
            ->sum(fn ($v) => Dinero::centavos((string) $v));
    }

    /** Obligaciones de tercero de la planilla, para el detalle del informe. */
    public function terceros(Planilla $planilla)
    {
        return PlanillaObligacionTercero::where('planilla_id', $planilla->id)
            ->with('gasto.cuotas')
            ->orderBy('tercero')
            ->get();
    }
}
