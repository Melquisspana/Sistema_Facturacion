<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vacaciones anuales (Código de Trabajo Art. 177): 15 días de salario más un 30 %, por cada
 * año cumplido desde la fecha de ingreso. Parámetros en `config/planilla_prestaciones.php`.
 *
 * La planilla de vacaciones de un mes paga, a cada persona, el ÚLTIMO año de servicio
 * cumplido hasta el fin de ese mes, si todavía no lo cobró. Lo normal es pagarlas algún
 * mes después del aniversario: por eso entra quien cumplió este mes o en los 11 anteriores.
 *
 * UN AÑO DE SERVICIO YA ESTÁ PAGADO si:
 *  - el año de su aniversario es igual o anterior a «Vacaciones pagadas hasta» de la
 *    ficha, que es lo que se pagó fuera del sistema; o
 *  - hay una planilla de vacaciones no anulada que lo incluye. Qué año pagó una planilla
 *    sale de su mes: la de un mes entre el aniversario y el siguiente paga ese año. Los
 *    borradores cuentan, porque están en curso.
 *
 * Todo en centavos enteros, y se redondea una sola vez, al centavo, la mitad hacia arriba.
 */
final class VacacionesAnuales
{
    public const CLASE = 'vacaciones';

    /** 408.80 ÷ 30 × 15 × 1.30 = 265.72. */
    public function monto(int $mensual): int
    {
        $p = config('planilla_prestaciones.vacaciones');
        $porcentaje = 100 + Dinero::centavos((string) $p['recargo']);

        return $this->fraccion($mensual * $p['dias'] * $porcentaje, $p['dias_del_mes'] * 100);
    }

    /**
     * Lo que le toca a una persona en la planilla de vacaciones del mes de `$pago`.
     *
     * `avisar` = si el motivo por el que queda fuera merece un aviso. Que ya haya cobrado o
     * que no cumpla un año es lo normal y no se avisa; que falte la fecha de ingreso, sí.
     *
     * @return array{centavos: int, falta: ?string, fuera: ?string, avisar: bool, concepto: ?string, nota: ?string}
     */
    public function calcular(PlanillaEmpleado $empleado, ?int $mensual, CarbonImmutable $pago): array
    {
        $estado = $this->estado($empleado, $pago, $pago->format('Y-m'));
        $salida = ['centavos' => 0, 'falta' => null, 'fuera' => $estado['fuera'], 'avisar' => $estado['avisar'], 'concepto' => null, 'nota' => null];

        if ($estado['fuera'] !== null) {
            return $salida;
        }

        $salida['concepto'] = 'Vacaciones';
        // Si el aniversario no es de este mes, la línea dice cuándo fue.
        $salida['nota'] = $estado['del_mes'] ? null : 'Cumplió aniversario el '.$estado['aniversario']->format('d/m/Y').'.';

        if ($mensual === null) {
            return ['falta' => 'el sueldo'] + $salida;
        }

        return ['centavos' => $this->monto($mensual)] + $salida;
    }

    /**
     * Para el aviso del inicio: cuántos tienen vacaciones por pagar en el mes de `$hoy`, y
     * cuántos de ellos cumplen este mes. Aquí cuenta también la planilla de este mes:
     * quien ya está en ella ya no falta.
     *
     * @return array{por_pagar: int, del_mes: int}
     */
    public function pendientes(CarbonImmutable $hoy): array
    {
        $cuenta = ['por_pagar' => 0, 'del_mes' => 0];
        $inicio = $hoy->startOfMonth()->toDateString();

        $empleados = PlanillaEmpleado::where('activo', true)
            ->where(fn ($q) => $q->whereNull('baja_el')->orWhereDate('baja_el', '>=', $inicio))
            ->get();

        foreach ($empleados as $empleado) {
            $estado = $this->estado($empleado, $hoy, null);

            if ($estado['fuera'] === null) {
                $cuenta['por_pagar']++;
                $cuenta['del_mes'] += $estado['del_mes'] ? 1 : 0;
            }
        }

        return $cuenta;
    }

    /**
     * El año de servicio que paga la planilla de vacaciones del mes de `$mes`: del
     * aniversario anterior al último cumplido hasta el fin de ese mes. Null si no cumple
     * un año o no tiene fecha de ingreso. Lo usa el recibo para firmar.
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}|null
     */
    public function anioDeServicio(PlanillaEmpleado $empleado, CarbonImmutable $mes): ?array
    {
        if ($empleado->alta_el === null) {
            return null;
        }

        $ingreso = CarbonImmutable::parse($empleado->alta_el->toDateString());
        $anios = $this->aniosCumplidos($ingreso, $mes);

        return $anios < 1 ? null : [
            'desde' => $ingreso->addYearsNoOverflow($anios - 1),
            'hasta' => $ingreso->addYearsNoOverflow($anios),
        ];
    }

    /** Años cumplidos hasta el fin del mes. Quien entró un 29 de febrero los cumple el 28. */
    private function aniosCumplidos(CarbonImmutable $ingreso, CarbonImmutable $mes): int
    {
        $finDeMes = $mes->endOfMonth()->startOfDay();
        $anios = $finDeMes->year - $ingreso->year;

        return $ingreso->addYearsNoOverflow($anios) > $finDeMes ? $anios - 1 : $anios;
    }

    /**
     * @param  ?string  $salvoPeriodo  la planilla que se está armando, que no cuenta como pago
     * @return array{fuera: ?string, avisar: bool, aniversario: ?CarbonImmutable, del_mes: bool}
     */
    private function estado(PlanillaEmpleado $empleado, CarbonImmutable $mes, ?string $salvoPeriodo): array
    {
        $fuera = fn (string $motivo, bool $avisar = false) => ['fuera' => $motivo, 'avisar' => $avisar, 'aniversario' => null, 'del_mes' => false];

        if ($empleado->alta_el === null) {
            return $fuera('no tiene fecha de ingreso, y sin ella no se sabe cuándo le tocan las vacaciones', true);
        }

        $ingreso = CarbonImmutable::parse($empleado->alta_el->toDateString());
        $anios = $this->aniosCumplidos($ingreso, $mes);

        if ($anios < 1) {
            return $fuera('todavía no cumple un año');
        }

        $aniversario = $ingreso->addYearsNoOverflow($anios);
        $pagadasHasta = $empleado->vacaciones_pagadas_hasta;

        if ($pagadasHasta !== null && $aniversario->year <= $pagadasHasta) {
            return $fuera('según su ficha, ya cobró las vacaciones hasta '.$pagadasHasta);
        }

        $pagado = DB::table('planilla_detalles as d')
            ->join('planillas as p', 'p.id', '=', 'd.planilla_id')
            ->where('d.planilla_empleado_id', $empleado->id)
            ->where('p.clase', self::CLASE)
            ->where('p.estado', '!=', 'anulada')
            ->when($salvoPeriodo !== null, fn ($q) => $q->where('p.periodo', '!=', $salvoPeriodo))
            ->whereDate('p.desde', '>=', $aniversario->startOfMonth()->toDateString())
            ->whereDate('p.desde', '<', $ingreso->addYearsNoOverflow($anios + 1)->startOfMonth()->toDateString())
            ->value('p.periodo');

        if ($pagado !== null) {
            return $fuera('ya cobró las vacaciones del año cumplido el '.$aniversario->format('d/m/Y').', en la planilla '.$pagado);
        }

        return ['fuera' => null, 'avisar' => false, 'aniversario' => $aniversario, 'del_mes' => $aniversario >= $mes->startOfMonth()];
    }

    /** Al centavo, la mitad hacia arriba: lo mismo que el aguinaldo. */
    private function fraccion(int $numerador, int $denominador): int
    {
        return intdiv(2 * $numerador + $denominador, 2 * $denominador);
    }
}
