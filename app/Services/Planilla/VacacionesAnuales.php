<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vacaciones anuales (Código de Trabajo Art. 177): 15 días de salario más un 30 %, en el
 * mes del aniversario de la fecha de ingreso. Parámetros en `config/planilla_prestaciones.php`.
 *
 * La planilla de vacaciones de un mes paga, a cada persona, el ÚLTIMO año de servicio
 * cumplido hasta el fin de ese mes. Si el aniversario cayó en uno de los 11 meses
 * anteriores y ese año no se pagó, entra como atrasado: así nadie se queda sin cobrar
 * aunque la planilla se haga tarde.
 *
 * QUÉ AÑO PAGÓ UNA PLANILLA no se guarda en ninguna columna: sale de su mes. Una
 * planilla de vacaciones de un mes entre el aniversario y el siguiente paga ese año.
 * Las anuladas no cuentan; los borradores sí, porque están en curso.
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

        $fecha = $estado['aniversario']->format('d/m/Y');
        $salida['concepto'] = 'Vacaciones, año cumplido el '.$fecha.($estado['atrasado'] ? ' (atrasadas)' : '');
        $salida['nota'] = $estado['atrasado'] ? 'Vacaciones atrasadas: cumplió aniversario el '.$fecha.'.' : null;

        if ($mensual === null) {
            return ['falta' => 'el sueldo'] + $salida;
        }

        return ['centavos' => $this->monto($mensual)] + $salida;
    }

    /**
     * Para el aviso del inicio: quién tiene vacaciones por pagar en el mes de `$hoy`.
     * Aquí cuenta también la planilla de este mes: quien ya está en ella ya no falta.
     *
     * @return array{del_mes: int, atrasados: int}
     */
    public function pendientes(CarbonImmutable $hoy): array
    {
        $cuenta = ['del_mes' => 0, 'atrasados' => 0];
        $inicio = $hoy->startOfMonth()->toDateString();

        $empleados = PlanillaEmpleado::where('activo', true)
            ->where(fn ($q) => $q->whereNull('baja_el')->orWhereDate('baja_el', '>=', $inicio))
            ->get();

        foreach ($empleados as $empleado) {
            $estado = $this->estado($empleado, $hoy, null);

            if ($estado['fuera'] === null) {
                $cuenta[$estado['atrasado'] ? 'atrasados' : 'del_mes']++;
            }
        }

        return $cuenta;
    }

    /**
     * @param  ?string  $salvoPeriodo  la planilla que se está armando, que no cuenta como pago
     * @return array{fuera: ?string, avisar: bool, aniversario: ?CarbonImmutable, atrasado: bool}
     */
    private function estado(PlanillaEmpleado $empleado, CarbonImmutable $mes, ?string $salvoPeriodo): array
    {
        $fuera = fn (string $motivo, bool $avisar = false) => ['fuera' => $motivo, 'avisar' => $avisar, 'aniversario' => null, 'atrasado' => false];

        if ($empleado->alta_el === null) {
            return $fuera('no tiene fecha de ingreso, y sin ella no se sabe cuándo le tocan las vacaciones', true);
        }

        $ingreso = CarbonImmutable::parse($empleado->alta_el->toDateString());
        $finDeMes = $mes->endOfMonth()->startOfDay();

        // El último aniversario hasta el fin del mes. Quien entró un 29 de febrero lo
        // cumple el 28 en los años que no son bisiestos.
        $anios = $finDeMes->year - $ingreso->year;
        if ($ingreso->addYearsNoOverflow($anios) > $finDeMes) {
            $anios--;
        }

        if ($anios < 1) {
            return $fuera('todavía no cumple un año');
        }

        $aniversario = $ingreso->addYearsNoOverflow($anios);
        $desde = config('planilla_prestaciones.vacaciones.aniversarios_desde');

        if ($desde !== null && $aniversario->toDateString() < $desde) {
            return $fuera('el aniversario del '.$aniversario->format('d/m/Y').' es de antes del sistema');
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

        return ['fuera' => null, 'avisar' => false, 'aniversario' => $aniversario, 'atrasado' => $aniversario < $mes->startOfMonth()];
    }

    /** Al centavo, la mitad hacia arriba: lo mismo que el aguinaldo. */
    private function fraccion(int $numerador, int $denominador): int
    {
        return intdiv(2 * $numerador + $denominador, 2 * $denominador);
    }
}
