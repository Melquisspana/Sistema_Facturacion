<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Aguinaldo, Quincena 25, vacaciones e indemnización: lo que le toca a cada persona, con los
 * parámetros de `config/planilla_prestaciones.php`. Las vacaciones las resuelve
 * {@see VacacionesAnuales}; la indemnización y la prestación por renuncia, {@see IndemnizacionBaja}.
 *
 * Son planillas de su propia clase y usan todo lo demás tal cual: preparar, confirmar,
 * pagar y recibos. El monto entra como un ingreso más, y por eso se puede corregir a mano
 * en la pantalla. Es una propuesta, igual que ISSS, AFP y renta.
 *
 * Valen para quien va con y sin descuentos de ley: la ley habla de «todo patrono» y «sus
 * trabajadores», y Melqui lo confirmó. Ninguna de las dos lleva ISSS ni AFP. Las vacaciones
 * sí los llevan para quien va con descuentos ({@see DescuentosLey::aplicaA()}).
 *
 * Todo en centavos enteros: el monto es una fracción, y se redondea una sola vez al final.
 */
final class PrestacionAnual
{
    /** La indemnización y la prestación por renuncia también: no pagan tiempo de un período. */
    public const CLASES = ['aguinaldo', 'quincena25', VacacionesAnuales::CLASE, IndemnizacionBaja::CLASES['despido'], IndemnizacionBaja::CLASES['renuncia']];

    public function __construct(
        private VacacionesAnuales $vacaciones,
        private IndemnizacionBaja $indemnizacion,
    ) {}

    public function es(string $clase): bool
    {
        return in_array($clase, self::CLASES, true);
    }

    /**
     * Comprueba que se pueda pagar en esa fecha. Se llama antes de abrir la planilla.
     */
    public function exigirFechaDePago(string $clase, ?string $fecha): void
    {
        if (blank($fecha)) {
            throw ValidationException::withMessages(['fecha_pago' => 'Indicá la fecha de pago.']);
        }

        $pago = CarbonImmutable::parse($fecha);

        // Las vacaciones y la indemnización se pagan cualquier mes.
        if ($clase === VacacionesAnuales::CLASE || IndemnizacionBaja::es($clase)) {
            return;
        }

        $parametros = $this->parametros($clase, $pago);

        if ($parametros === null) {
            throw ValidationException::withMessages([
                'clase' => 'No hay parámetros de '.Planilla::CLASES[$clase].' para '.$pago->year.'. Hay que cargarlos en la configuración.',
            ]);
        }

        $desde = $pago->year.'-'.$parametros['pago_desde'];
        $hasta = $pago->year.'-'.$parametros['pago_hasta'];

        if ($pago->toDateString() < $desde || $pago->toDateString() > $hasta) {
            throw ValidationException::withMessages([
                'fecha_pago' => Planilla::CLASES[$clase].' se paga del '.CarbonImmutable::parse($desde)->format('d/m/Y')
                    .' al '.CarbonImmutable::parse($hasta)->format('d/m/Y').'.',
            ]);
        }
    }

    /**
     * @return array{centavos: int, falta: ?string, fuera: ?string, avisar?: bool, concepto?: ?string, nota?: ?string}
     *                                                                                                                 `falta` = no se puede calcular todavía; `fuera` = no le corresponde
     */
    public function calcular(string $clase, PlanillaEmpleado $empleado, ?int $mensual, CarbonImmutable $pago, ?int $salvoPlanilla = null): array
    {
        if ($clase === VacacionesAnuales::CLASE) {
            return $this->vacaciones->calcular($empleado, $mensual, $pago, $salvoPlanilla);
        }

        // Con el sueldo a la fecha de baja, no a la de pago: lo busca ella.
        if (IndemnizacionBaja::es($clase)) {
            return $this->indemnizacion->calcularPago($clase, $empleado, $salvoPlanilla);
        }

        $parametros = $this->parametros($clase, $pago);
        $corte = CarbonImmutable::parse($pago->year.'-'.$parametros['corte']);

        if ($mensual === null) {
            return ['centavos' => 0, 'falta' => 'el sueldo', 'fuera' => null];
        }

        if ($clase === 'quincena25' && $mensual > Dinero::centavos($parametros['salario_maximo'])) {
            return ['centavos' => 0, 'falta' => null, 'fuera' => 'gana más de $'.$parametros['salario_maximo'].' al mes'];
        }

        if ($empleado->alta_el === null) {
            return ['centavos' => 0, 'falta' => 'la fecha de ingreso', 'fuera' => null];
        }

        $ingreso = CarbonImmutable::parse($empleado->alta_el->toDateString());

        if ($ingreso > $corte) {
            return ['centavos' => 0, 'falta' => null, 'fuera' => 'entró después del corte'];
        }

        $anios = (int) floor($ingreso->diffInYears($corte));
        $dias = min((int) $ingreso->diffInDays($corte) + 1, $parametros['dias_del_anio']);

        $centavos = $clase === 'aguinaldo'
            ? $this->aguinaldo($mensual, $anios, $dias, $parametros)
            : $this->quincena25($mensual, $anios, $dias, $parametros);

        return ['centavos' => $centavos, 'falta' => null, 'fuera' => null];
    }

    /** Nombre con el que sale en el recibo: «Aguinaldo 2026». */
    public function concepto(string $clase, CarbonImmutable $pago): string
    {
        return Planilla::CLASES[$clase].' '.$pago->year;
    }

    /** Aviso si el monto pasa de lo exento de renta. */
    public function avisoDeRenta(string $clase, int $centavos, CarbonImmutable $pago): ?string
    {
        $exento = $clase === 'aguinaldo' ? $this->parametros($clase, $pago)['exento_renta'] ?? null : null;

        return $exento !== null && $centavos > Dinero::centavos($exento)
            ? 'pasa de $'.$exento.' exentos de renta: lo que excede no se calcula, revisalo con la contadora.'
            : null;
    }

    /** @param  array<string, mixed>  $parametros */
    private function aguinaldo(int $mensual, int $anios, int $dias, array $parametros): int
    {
        $delMes = $parametros['dias_del_mes'];

        if ($anios < 1) {
            return $this->fraccion($mensual * $parametros['dias_proporcional'] * $dias, $delMes * $parametros['dias_del_anio']);
        }

        $deSalario = collect($parametros['tramos'])->last(fn (array $t) => $anios >= $t['desde_anios'])['dias'];

        return $this->fraccion($mensual * $deSalario, $delMes);
    }

    /** @param  array<string, mixed>  $parametros */
    private function quincena25(int $mensual, int $anios, int $dias, array $parametros): int
    {
        $porcentaje = Dinero::centavos($parametros['porcentaje']);

        return $anios >= 1
            ? $this->fraccion($mensual * $porcentaje, 10000)
            : $this->fraccion($mensual * $porcentaje * $dias, 10000 * $parametros['dias_del_anio']);
    }

    /** Al centavo, la mitad hacia arriba. */
    private function fraccion(int $numerador, int $denominador): int
    {
        return intdiv(2 * $numerador + $denominador, 2 * $denominador);
    }

    /** @return array<string, mixed>|null */
    private function parametros(string $clase, CarbonImmutable $pago): ?array
    {
        if ($clase === 'aguinaldo') {
            return config('planilla_prestaciones.aguinaldo')[$pago->year] ?? null;
        }

        return collect(config('planilla_prestaciones.quincena25'))
            ->filter(fn (array $v) => $v['vigente_desde'] <= $pago->toDateString())
            ->sortBy('vigente_desde')
            ->last();
    }
}
