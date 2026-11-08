<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vacaciones anuales (Código de Trabajo Art. 177): 15 días de salario más un 30 %, por cada
 * año cumplido desde la fecha de ingreso. Parámetros en `config/planilla_prestaciones.php`.
 *
 * SE PAGAN DE A UNA PERSONA. Cada pago es una planilla de clase `vacaciones` con una sola
 * línea y `individual_de` = esa persona; puede haber varios en el mismo mes. Paga el ÚLTIMO
 * año de servicio cumplido hasta el fin del mes de la fecha de pago, si todavía no se
 * cobró. Lo normal es pagarlas algún mes después del aniversario: por eso vale quien
 * cumplió ese mes o en los 11 anteriores.
 *
 * UN AÑO DE SERVICIO YA ESTÁ PAGADO si:
 *  - el año de su aniversario es igual o anterior a «Vacaciones pagadas hasta» de la
 *    ficha, que es lo que se pagó fuera del sistema; o
 *  - hay un pago de vacaciones no anulado que lo incluye. Un pago confirmado guarda el
 *    año que pagó, y es el mismo año si termina a menos de seis meses de este aniversario
 *    (así una fecha de ingreso corregida no lo paga dos veces ni lo pierde). Sin año
 *    guardado (borradores, que cuentan porque están en curso, y pagos de antes), sale del
 *    mes del pago: uno entre el aniversario y el siguiente paga ese año.
 *
 * Todo en centavos enteros, y se redondea una sola vez, al centavo, la mitad hacia arriba.
 */
final class VacacionesAnuales
{
    public const CLASE = 'vacaciones';

    public function __construct(
        private SueldoHabitual $sueldos,
        private DescuentosLey $descuentos,
    ) {}

    /** 408.80 ÷ 30 × 15 × 1.30 = 265.72. */
    public function monto(int $mensual): int
    {
        $p = config('planilla_prestaciones.vacaciones');
        $porcentaje = 100 + Dinero::centavos((string) $p['recargo']);

        return $this->fraccion($mensual * $p['dias'] * $porcentaje, $p['dias_del_mes'] * 100);
    }

    /**
     * Lo que le toca a una persona en un pago de vacaciones con fecha `$pago`.
     *
     * `avisar` = si el motivo por el que queda fuera merece un aviso. Que ya haya cobrado o
     * que no cumpla un año es lo normal y no se avisa; que falte la fecha de ingreso, sí.
     *
     * @param  ?int  $salvoPlanilla  el pago que se está armando, que no cuenta como pagado
     * @return array{centavos: int, falta: ?string, fuera: ?string, avisar: bool, concepto: ?string, nota: ?string}
     */
    public function calcular(PlanillaEmpleado $empleado, ?int $mensual, CarbonImmutable $pago, ?int $salvoPlanilla = null): array
    {
        $estado = $this->estado($empleado, $pago, $salvoPlanilla);
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
     * Quién tiene vacaciones por pagar con fecha de pago `$pago`, con lo que se le pagaría.
     * El neto es una estimación: el pago lo calcula de nuevo, y se puede corregir.
     *
     * @return array<int, array{empleado: PlanillaEmpleado, aniversario: CarbonImmutable, desde: CarbonImmutable, del_mes: bool, bruto: ?int, neto: ?int}>
     */
    public function porPagar(CarbonImmutable $pago): array
    {
        $inicio = $pago->startOfMonth()->toDateString();
        $empleados = PlanillaEmpleado::where('activo', true)
            ->where(fn ($q) => $q->whereNull('baja_el')->orWhereDate('baja_el', '>=', $inicio))
            ->orderBy('nombre')
            ->get();
        $mensuales = $this->sueldos->mensualesEn($empleados, $pago->toDateString());
        $filas = [];

        foreach ($empleados as $empleado) {
            $estado = $this->estado($empleado, $pago, null);

            if ($estado['fuera'] !== null) {
                continue;
            }

            $bruto = isset($mensuales[$empleado->id]) ? $this->monto($mensuales[$empleado->id]) : null;

            $filas[] = [
                'empleado' => $empleado,
                'aniversario' => $estado['aniversario'],
                'desde' => $this->anioDeServicio($empleado, $pago)['desde'],
                'del_mes' => $estado['del_mes'],
                'bruto' => $bruto,
                'neto' => $bruto === null ? null : $bruto - $this->descuentosEstimados($empleado, $bruto, $inicio),
            ];
        }

        return $filas;
    }

    /**
     * Para el enlace del inicio: cuántos tienen vacaciones por pagar este mes, y cuántos
     * de ellos cumplen este mes.
     *
     * @return array{por_pagar: int, del_mes: int}
     */
    public function pendientes(CarbonImmutable $hoy): array
    {
        $filas = $this->porPagar($hoy);

        return ['por_pagar' => count($filas), 'del_mes' => count(array_filter($filas, fn (array $f) => $f['del_mes']))];
    }

    /**
     * El año de servicio que paga un pago de vacaciones del mes de `$mes`: del aniversario
     * anterior al último cumplido hasta el fin de ese mes. Null si no cumple un año o no
     * tiene fecha de ingreso.
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

    /**
     * El año de servicio de la línea de un pago: el que quedó escrito al confirmarlo, para
     * que el recibo firmado no cambie. Solo si está vacía (un pago sin confirmar) se calcula.
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}|null
     */
    public function anioDeLinea(PlanillaDetalle $detalle, CarbonImmutable $mes): ?array
    {
        if ($detalle->periodo_desde !== null && $detalle->periodo_hasta !== null) {
            return [
                'desde' => CarbonImmutable::parse($detalle->periodo_desde->toDateString()),
                'hasta' => CarbonImmutable::parse($detalle->periodo_hasta->toDateString()),
            ];
        }

        return $detalle->empleado === null ? null : $this->anioDeServicio($detalle->empleado, $mes);
    }

    /** ISSS, AFP y renta que le tocarían, igual que los pone el pago (como una quincena). */
    private function descuentosEstimados(PlanillaEmpleado $empleado, int $bruto, string $fecha): int
    {
        if (! $empleado->con_descuentos_ley || ! config('planilla_prestaciones.vacaciones.con_descuentos_ley')) {
            return 0;
        }

        $calculo = $this->descuentos->calcular($bruto, $fecha);

        return $calculo === null ? 0 : array_sum(array_column($calculo['conceptos'], 'centavos'));
    }

    /** Años cumplidos hasta el fin del mes. Quien entró un 29 de febrero los cumple el 28. */
    private function aniosCumplidos(CarbonImmutable $ingreso, CarbonImmutable $mes): int
    {
        $finDeMes = $mes->endOfMonth()->startOfDay();
        $anios = $finDeMes->year - $ingreso->year;

        return $ingreso->addYearsNoOverflow($anios) > $finDeMes ? $anios - 1 : $anios;
    }

    /**
     * @param  ?int  $salvoPlanilla  el pago que se está armando, que no cuenta como pagado
     * @return array{fuera: ?string, avisar: bool, aniversario: ?CarbonImmutable, del_mes: bool}
     */
    private function estado(PlanillaEmpleado $empleado, CarbonImmutable $mes, ?int $salvoPlanilla): array
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
            ->when($salvoPlanilla !== null, fn ($q) => $q->where('p.id', '!=', $salvoPlanilla))
            ->where(fn ($q) => $q
                // Con el año guardado al confirmar, decide ese año y no el mes del pago: si
                // después se corrige la fecha de ingreso, el año ya pagado sigue pagado. Es el
                // mismo año si termina a menos de seis meses de este aniversario (se cruzan
                // más de la mitad); uno que termina a seis meses o más es otro año, en las
                // dos direcciones de la corrección.
                ->where(fn ($q) => $q->whereNotNull('d.periodo_hasta')
                    ->whereDate('d.periodo_hasta', '>', $aniversario->subMonthsNoOverflow(6)->toDateString())
                    ->whereDate('d.periodo_hasta', '<', $aniversario->addMonthsNoOverflow(6)->toDateString()))
                // Sin año guardado (borradores y pagos de antes), por el mes del pago.
                ->orWhere(fn ($q) => $q->whereNull('d.periodo_hasta')
                    ->whereDate('p.desde', '>=', $aniversario->startOfMonth()->toDateString())
                    ->whereDate('p.desde', '<', $ingreso->addYearsNoOverflow($anios + 1)->startOfMonth()->toDateString())))
            ->first(['p.fecha_pago', 'p.desde']);

        if ($pagado !== null) {
            $fecha = CarbonImmutable::parse($pagado->fecha_pago ?? $pagado->desde)->format('d/m/Y');

            return $fuera('ya cobró las vacaciones del año cumplido el '.$aniversario->format('d/m/Y').', en el pago del '.$fecha);
        }

        return ['fuera' => null, 'avisar' => false, 'aniversario' => $aniversario, 'del_mes' => $aniversario >= $mes->startOfMonth()];
    }

    /** Al centavo, la mitad hacia arriba: lo mismo que el aguinaldo. */
    private function fraccion(int $numerador, int $denominador): int
    {
        return intdiv(2 * $numerador + $denominador, 2 * $denominador);
    }
}
