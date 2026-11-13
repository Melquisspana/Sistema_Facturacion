<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * ISSS, AFP y renta de una quincena, a partir de `config/planilla_descuentos.php`.
 *
 * ES UNA PROPUESTA. Quien prepara la planilla la revisa y la puede corregir; lo que
 * vale es el importe guardado en la línea, no este cálculo.
 *
 * TODO EN CENTAVOS ENTEROS, sin un solo número decimal en el camino: 7.25 no existe
 * exacto como float, y en una planilla ese error termina siendo un centavo de más en
 * el recibo de alguien. Las tasas se leen con {@see Dinero::centavos()}: «7.25» pasa a
 * 725 centésimas de punto.
 *
 * LO QUE NO HACE, y avisa en vez de callarlo: la deducción de US$1,600 del Tramo II y
 * la renta de pagos extraordinarios, que necesitan una regla que el decreto no da para
 * la quincena.
 *
 * EL RECÁLCULO DE JUNIO Y DICIEMBRE lo calcula {@see recalcularRenta()} con lo
 * acumulado del año, que junta {@see AcumuladoRenta}.
 *
 * Calcula las planillas normales quincenales, con la tabla quincenal, y las mensuales,
 * con la tabla mensual y el sueldo del mes entero (Melqui, 12/11/2026). Los topes son por
 * mes, repartidos entre los pagos del mes ({@see cotizacion()}). Y las vacaciones, si
 * `planilla_prestaciones.vacaciones.con_descuentos_ley` lo dice: se calculan igual que
 * una quincena, sobre el monto de las vacaciones solo. Lo mismo la indemnización y la
 * prestación por renuncia, con `planilla_prestaciones.indemnizacion.con_descuentos_ley`, que
 * por defecto dice que no.
 */
final class DescuentosLey
{
    /** ¿Esta planilla lleva cálculo? */
    public function aplicaA(Planilla $planilla): bool
    {
        if ($planilla->clase === VacacionesAnuales::CLASE) {
            return (bool) config('planilla_prestaciones.vacaciones.con_descuentos_ley');
        }

        if (IndemnizacionBaja::es((string) $planilla->clase)) {
            return (bool) config('planilla_prestaciones.indemnizacion.con_descuentos_ley');
        }

        return in_array($planilla->tipo_periodo, ['quincenal', 'mensual'], true) && $planilla->clase === 'regular';
    }

    /** ¿Hay parámetros vigentes para esa fecha? */
    public function hayVigentes(string $fecha): bool
    {
        return $this->versionEn($fecha) !== null;
    }

    /**
     * Lo que cotiza en esta línea: salario más ingresos, sin lo que no forma parte de
     * la base.
     *
     * @param  array<int, array{tipo: string, concepto: string, importe?: mixed}>  $conceptos
     * @return array{base: int, avisos: array<int, string>}
     */
    public function baseDeLinea(int $salario, array $conceptos): array
    {
        $fuera = array_map($this->normalizar(...), config('planilla_descuentos.ingresos_fuera_de_base'));
        $extraordinarios = array_map($this->normalizar(...), config('planilla_descuentos.ingresos_extraordinarios'));

        $base = $salario;
        $avisos = [];

        foreach ($conceptos as $concepto) {
            if (($concepto['tipo'] ?? null) !== 'ingreso' || blank($concepto['importe'] ?? null)) {
                continue;
            }

            $nombre = $this->normalizar((string) ($concepto['concepto'] ?? ''));

            if (in_array($nombre, $extraordinarios, true)) {
                $avisos[] = 'Los pagos extraordinarios llevan otro procedimiento de renta: revisalo con la contadora.';
            }

            if (! in_array($nombre, $fuera, true)) {
                $base += Dinero::centavos((string) $concepto['importe']);
            }
        }

        return ['base' => $base, 'avisos' => array_values(array_unique($avisos))];
    }

    /**
     * `$mes` es lo ya pagado a la persona en el mismo mes, para el tope mensual
     * ({@see cotizacion()}). Sin él, el tope solo se reparte entre los pagos del mes: así lo
     * usan las estimaciones.
     *
     * @param  array{pagos: int, previos: array<int, array{base: int, pagos: int}>, cotizado: array<string, int>}|null  $mes
     * @return array{conceptos: array<string, array{ley: string, concepto: string, tercero: string, centavos: int}>, avisos: array<int, string>}|null
     *                                                                                                                                                null si no hay parámetros para esa fecha
     */
    public function calcular(int $base, string $fecha, ?array $mes = null): ?array
    {
        $version = $this->versionEn($fecha);

        if ($version === null) {
            return null;
        }

        $base = max($base, 0);
        $isss = $this->cotizacion($base, $version['isss'], 'isss', $mes);
        $afp = $this->cotizacion($base, $version['afp'], 'afp', $mes);

        // Un solo pago en el mes (la planilla mensual): la tabla mensual.
        $tramos = ($mes['pagos'] ?? null) === 1 ? $version['renta']['tramos_mensuales'] : $version['renta']['tramos'];
        [$renta, $aviso] = $this->renta($base - $isss - $afp, $tramos);

        $avisos = $aviso !== null ? [$aviso] : [];

        return [
            'conceptos' => [
                'isss' => $this->concepto('isss', $version['isss'], $isss),
                'afp' => $this->concepto('afp', $version['afp'], $afp),
                'renta' => $this->concepto('renta', $version['renta'], $renta),
            ],
            'avisos' => $avisos,
        ];
    }

    /**
     * ¿Esta planilla lleva recálculo de renta? El último pago de junio o de diciembre:
     * en la quincenal, la segunda quincena; en la mensual, la del mes.
     */
    public function esRecalculo(Planilla $planilla): bool
    {
        return $this->aplicaA($planilla)
            && $planilla->clase === 'regular'
            && in_array($planilla->hasta->month, [6, 12], true)
            && $planilla->hasta->isLastOfMonth();
    }

    /**
     * La renta de junio o diciembre por recálculo (Decreto 10, Art. 1 lit. f): la tabla
     * semestral o anual sobre lo gravado del año, menos lo ya retenido. Si da negativo
     * no se retiene nada: el decreto no manda devolver.
     *
     * @param  int  $gravadoAcumulado  remuneración gravada del año, INCLUIDO este pago
     * @param  int  $rentaRetenida  renta retenida en los pagos anteriores del año
     * @param  string  $fecha  del pago; su mes elige la tabla
     * @return array{centavos: int, segun_tabla: int, avisos: array<int, string>}|null null si no hay parámetros para esa fecha
     */
    public function recalcularRenta(int $gravadoAcumulado, int $rentaRetenida, string $fecha): ?array
    {
        $mes = CarbonImmutable::parse($fecha)->month;

        if (! in_array($mes, [6, 12], true)) {
            throw new \InvalidArgumentException('Solo junio y diciembre llevan recálculo de renta.');
        }

        $tabla = $this->versionEn($fecha)['recalculo'][$mes] ?? null;

        if ($tabla === null) {
            return null;
        }

        $gravada = max($gravadoAcumulado, 0);
        $avisos = [];
        $deduccion = $tabla['deduccion'] ?? null;

        if ($deduccion !== null && $gravada > 0 && $gravada <= Dinero::centavos($deduccion['hasta'])) {
            $gravada = max($gravada - Dinero::centavos($deduccion['importe']), 0);
            $avisos[] = $deduccion['aviso'];
        }

        [$segunTabla, $aviso] = $this->renta($gravada, $tabla['tramos']);

        if ($aviso !== null) {
            $avisos[] = $aviso;
        }

        return [
            'centavos' => max($segunTabla - $rentaRetenida, 0),
            'segun_tabla' => $segunTabla,
            'avisos' => $avisos,
        ];
    }

    /**
     * Lo que se propone como acumulado de las quincenas anteriores al sistema: el sueldo
     * de siempre repetido. La persona lo confirma o lo corrige; esto no lo decide.
     *
     * @param  int  $baseQuincenal  lo que cotiza en una quincena habitual
     * @return array{gravado: int, renta: int}|null null si no hay parámetros para esa fecha
     */
    public function proponerAcumulado(int $baseQuincenal, int $quincenas, string $fecha): ?array
    {
        $calculo = $this->calcular($baseQuincenal, $fecha);

        if ($calculo === null) {
            return null;
        }

        $c = array_map(fn (array $concepto) => $concepto['centavos'], $calculo['conceptos']);
        $quincenas = max($quincenas, 0);

        return [
            'gravado' => (max($baseQuincenal, 0) - $c['isss'] - $c['afp']) * $quincenas,
            'renta' => $c['renta'] * $quincenas,
        ];
    }

    /**
     * Cuántos pagos de esta clase hay en un mes: entre ellos se reparte el tope mensual. La
     * planilla mensual normal es uno solo y lleva el tope entero.
     */
    public function pagosDelMes(Planilla $planilla): int
    {
        return $planilla->tipo_periodo === 'mensual' && $planilla->clase === 'regular'
            ? 1
            : (int) config('planilla.pagos_por_mes');
    }

    /**
     * Lo que cotiza este pago. El tope de ley es POR MES (Melqui, 12/11/2026), y se
     * descuenta repartido en los pagos.
     *
     * Cada pago cotiza, como siempre, hasta su parte del tope (tope ÷ pagos del mes), y
     * nunca más de lo que le falta al mes: tasa × tope, menos lo ya cotizado en los pagos
     * confirmados del mismo mes. Con unas vacaciones en el mes, la quincena que viene
     * después cotiza solo lo que falta para el tope.
     *
     * Después del primer pago del mes, si lo cobrado en el mes ya llega al tope o un pago
     * anterior quedó cortado por su parte del tope, este pago completa lo que el mes tiene
     * que cotizar: con quincenas de 400 y 700, la segunda completa los 30.00. Un mes de
     * quincenas por debajo del tope, o de dos iguales, queda como antes.
     *
     * @param  array<string, mixed>  $parametros
     * @param  array{pagos: int, previos: array<int, array{base: int, pagos: int}>, cotizado: array<string, int>}|null  $mes
     */
    private function cotizacion(int $base, array $parametros, string $ley, ?array $mes): int
    {
        if ($parametros['tope_mensual'] === null) {
            return $this->porcentaje($base, $parametros['tasa']);
        }

        $tope = Dinero::centavos($parametros['tope_mensual']);
        $parte = fn (int $pagos) => intdiv($tope, max($pagos, 1));
        $cotiza = $this->porcentaje(min($base, $parte($mes['pagos'] ?? (int) config('planilla.pagos_por_mes'))), $parametros['tasa']);

        if ($mes === null) {
            return $cotiza;
        }

        $yaCotizado = $mes['cotizado'][$ley] ?? 0;
        $delMes = array_sum(array_column($mes['previos'], 'base')) + $base;
        $cortado = collect($mes['previos'])->contains(fn (array $p) => $p['base'] > $parte($p['pagos']));

        if ($mes['previos'] !== [] && ($cortado || $delMes >= $tope)) {
            $cotiza = max($cotiza, $this->porcentaje(min($delMes, $tope), $parametros['tasa']) - $yaCotizado);
        }

        return max(min($cotiza, $this->porcentaje($tope, $parametros['tasa']) - $yaCotizado), 0);
    }

    /**
     * Cuota fija + porcentaje sobre el exceso del tramo que le toca a la remuneración
     * gravada.
     *
     * @param  array<int, array<string, string>>  $tramos
     * @return array{0: int, 1: ?string}
     */
    private function renta(int $gravada, array $tramos): array
    {
        $tramo = $tramos[0];

        foreach ($tramos as $candidato) {
            if ($gravada >= Dinero::centavos($candidato['desde'])) {
                $tramo = $candidato;
            }
        }

        $exceso = max($gravada - Dinero::centavos($tramo['exceso']), 0);

        return [
            Dinero::centavos($tramo['cuota']) + $this->porcentaje($exceso, $tramo['porcentaje']),
            $tramo['aviso'] ?? null,
        ];
    }

    /** Al centavo, la mitad hacia arriba: el único lugar donde se redondea. */
    private function porcentaje(int $centavos, string $tasa): int
    {
        return intdiv($centavos * Dinero::centavos($tasa) + 5000, 10000);
    }

    /**
     * @param  array<string, mixed>  $parametros
     * @return array{ley: string, concepto: string, tercero: string, centavos: int}
     */
    private function concepto(string $ley, array $parametros, int $centavos): array
    {
        return ['ley' => $ley, 'concepto' => $parametros['concepto'], 'tercero' => $parametros['tercero'], 'centavos' => $centavos];
    }

    /** @return array<string, mixed>|null */
    private function versionEn(string $fecha): ?array
    {
        return collect(config('planilla_descuentos.versiones'))
            ->filter(fn (array $v) => $v['vigente_desde'] <= $fecha)
            ->sortBy('vigente_desde')
            ->last();
    }

    private function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii(trim($texto)));
    }
}
