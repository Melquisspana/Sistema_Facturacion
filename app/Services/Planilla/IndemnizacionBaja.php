<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Indemnización por despido sin causa y prestación por renuncia voluntaria. Se pagan una
 * sola vez, cuando la persona se va, como PAGO DE ESA SOLA PERSONA, igual que las
 * vacaciones: una planilla de clase `indemnizacion` (despido) o `renuncia`, con una sola
 * línea e `individual_de` = esa persona. Sigue la revisión, confirmar, pagar y anular de
 * todas. Parámetros en `config/planilla_prestaciones.php`.
 *
 * EL MOTIVO se elige al dar de baja y queda en la bitácora de la baja ({@see BajaEmpleado}):
 * `planilla_empleados` no tiene columna para él. Con motivo «otro» no corresponde nada.
 *
 * UNA SOLA VEZ POR RELACIÓN LABORAL. La clave técnica del pago es el día de la baja, así
 * que la clave única de la base impide dos de la misma clase esa baja; {@see generada()}
 * frena además cualquier otro de las dos clases desde el ingreso actual, aunque sea de otra
 * baja. Uno anulado deja libre el lugar ({@see AnularPlanilla}) y se puede volver a generar.
 *
 * LO QUE QUEDA EN LA LÍNEA, como foto que el recibo imprime: el tiempo de servicio en
 * `periodo_desde`–`periodo_hasta` (lo escribe {@see ConfirmarPlanilla}) y el detalle del
 * cálculo en las observaciones.
 *
 * Todo en centavos enteros, y se redondea una sola vez, al centavo, la mitad hacia arriba.
 */
final class IndemnizacionBaja
{
    /** Motivo de la baja => clase del pago. */
    public const CLASES = ['despido' => 'indemnizacion', 'renuncia' => 'renuncia'];

    public const MOTIVOS = [
        'despido' => 'Despido sin causa',
        'renuncia' => 'Renuncia voluntaria',
        'otro' => 'Otro',
    ];

    private const BAJA = 'dio de baja al empleado';

    public function __construct(
        private SueldoHabitual $sueldos,
        private SalarioMinimo $minimos,
    ) {}

    public static function es(string $clase): bool
    {
        return in_array($clase, self::CLASES, true);
    }

    /** «indemnización» o «prestación por renuncia»: para «Calcular …». */
    public static function nombre(string $motivo): string
    {
        return $motivo === 'renuncia' ? 'prestación por renuncia' : 'indemnización';
    }

    /** «Pago de indemnización de Ana» o «Prestación por renuncia de Ana». */
    public static function titulo(string $clase, ?string $persona): string
    {
        $titulo = $clase === self::CLASES['renuncia'] ? 'Prestación por renuncia' : 'Pago de indemnización';

        return $persona === null ? $titulo : $titulo.' de '.$persona;
    }

    public static function motivoDeClase(string $clase): ?string
    {
        $motivo = array_search($clase, self::CLASES, true);

        return $motivo === false ? null : $motivo;
    }

    /** El motivo con que se dio de baja, de la bitácora. Null si la baja es de antes de preguntarlo. */
    public function motivoDeBaja(PlanillaEmpleado $empleado): ?string
    {
        if ($empleado->activo || $empleado->baja_el === null) {
            return null;
        }

        $motivo = Activity::where('subject_type', $empleado->getMorphClass())
            ->where('subject_id', $empleado->id)
            ->where('description', self::BAJA)
            ->latest('id')
            ->first()
            ?->getExtraProperty('motivo');

        return array_key_exists((string) $motivo, self::MOTIVOS) ? $motivo : null;
    }

    /**
     * El pago no anulado de esta relación laboral, de cualquiera de las dos clases: el que
     * va del ingreso actual en adelante, sea de la baja que sea. Quien se reactiva y vuelve
     * a darse de baja no cobra otra vez los mismos años; solo si se corrige su fecha de
     * ingreso a la de la recontratación cuenta como otra relación.
     */
    public function generada(PlanillaEmpleado $empleado, ?int $salvoPlanilla = null): ?Planilla
    {
        return Planilla::whereIn('clase', self::CLASES)
            ->where('individual_de', $empleado->id)
            ->where('estado', '!=', 'anulada')
            ->when($empleado->alta_el !== null, fn ($q) => $q->whereDate('desde', '>=', $empleado->alta_el->toDateString()))
            // Un borrador de una baja que después se corrigió no frena calcular de nuevo: su
            // monto es el de la fecha vieja y no se puede confirmar
            // ({@see PrepararPlanilla::reparosParaConfirmar()}). Uno confirmado sí frena.
            ->where(fn ($q) => $q->where('estado', '!=', 'borrador')
                ->when($empleado->baja_el !== null, fn ($q) => $q->orWhereDate('desde', $this->baja($empleado)->toDateString())))
            ->when($salvoPlanilla !== null, fn ($q) => $q->whereKeyNot($salvoPlanilla))
            ->latest('id')
            ->first();
    }

    /**
     * Lo que le toca por esta baja, con cada paso del cálculo para mostrarlo.
     *
     * `fuera` = no le corresponde, y por qué. `falta` = falta un dato para calcularlo.
     *
     * @param  ?string  $motivo  el de la bitácora si no se da; solo una baja sin motivo lo recibe de la pantalla
     * @param  ?int  $salvoPlanilla  el pago que se está armando, que no cuenta como ya generado
     * @return array<string, mixed>
     */
    public function calcular(PlanillaEmpleado $empleado, ?string $motivo = null, ?int $salvoPlanilla = null): array
    {
        $motivo ??= $this->motivoDeBaja($empleado);
        $salida = ['motivo' => $motivo, 'fuera' => null, 'falta' => null, 'avisar' => true, 'centavos' => 0, 'concepto' => null, 'nota' => null];

        if ($empleado->activo || $empleado->baja_el === null) {
            return ['fuera' => 'todavía está activa: primero hay que darle de baja'] + $salida;
        }

        if ($motivo === null) {
            return ['falta' => 'el motivo de la baja'] + $salida;
        }

        if (! isset(self::CLASES[$motivo])) {
            return ['fuera' => 'por el motivo de la baja no corresponde indemnización ni prestación por renuncia'] + $salida;
        }

        if (($ya = $this->generada($empleado, $salvoPlanilla)) !== null) {
            return ['fuera' => 'ya se generó el pago de esta baja ('.lcfirst(self::titulo($ya->clase, null)).')'] + $salida;
        }

        if ($empleado->alta_el === null) {
            return ['falta' => 'la fecha de ingreso'] + $salida;
        }

        $p = config('planilla_prestaciones.indemnizacion');
        $regla = $p[$motivo];
        $desde = CarbonImmutable::parse($empleado->alta_el->toDateString());
        $hasta = $this->baja($empleado);

        if ($hasta < $desde) {
            return ['falta' => 'una fecha de ingreso que no sea posterior a la baja'] + $salida;
        }

        $tiempo = $this->tiempo($desde, $hasta);
        $salida = ['desde' => $desde, 'hasta' => $hasta, 'tiempo' => $tiempo, 'regla' => $regla] + $salida;

        if ($tiempo['anios'] < $regla['anios_minimos']) {
            return ['fuera' => 'la prestación por renuncia voluntaria pide al menos '.$regla['anios_minimos']
                .' años de servicio continuo, y tiene '.$tiempo['texto']] + $salida;
        }

        $mensual = $this->sueldos->mensualesEn(collect([$empleado]), $hasta->toDateString())[$empleado->id] ?? null;

        if ($mensual === null) {
            return ['falta' => 'el sueldo a la fecha de baja'] + $salida;
        }

        try {
            $minimo = $this->minimos->vigenteEn($hasta->toDateString());
        } catch (ValidationException) {
            return ['falta' => 'el salario mínimo vigente a la fecha de baja'] + $salida;
        }

        $delMes = $p['dias_del_mes'];
        $delAnio = $p['dias_del_anio'];
        // Días de servicio contados a razón de 365 por año: 5 años y 92 días = 1,917.
        $servicio = $tiempo['anios'] * $delAnio + $tiempo['dias'];
        // El tope es en mínimos diarios; comparado al mes da lo mismo y no redondea nada.
        $tope = $regla['tope_minimos'] * $minimo;
        $base = min($mensual, $tope);
        $alMinimo = $regla['dias_por_anio'] * $servicio < $regla['minimo_dias'] * $delAnio;

        $centavos = $alMinimo
            ? $this->fraccion($base * $regla['minimo_dias'], $delMes)
            : $this->fraccion($base * $regla['dias_por_anio'] * $servicio, $delMes * $delAnio);

        $c = [
            'centavos' => $centavos,
            'mensual' => $mensual,
            'diario' => $this->conDecimales($mensual, $delMes, 4),
            'tope_diario' => $this->conDecimales($tope, $delMes, 4),
            'con_tope' => $mensual > $tope,
            'diario_usado' => $this->conDecimales($base, $delMes, 4),
            'anios_decimal' => $this->conDecimales($servicio * 100, $delAnio, 4),
            'dias_pagados' => $this->conDecimales($regla['dias_por_anio'] * $servicio * 100, $delAnio, 2),
            'al_minimo' => $alMinimo,
            'concepto' => $motivo === 'renuncia' ? 'Prestación por renuncia voluntaria' : 'Indemnización por despido',
        ] + $salida;

        return ['nota' => $this->detalle($c)] + $c;
    }

    /**
     * Lo de un pago de esta clase, para {@see PrestacionAnual}. Si la baja guardó su motivo,
     * tiene que ser el de la clase; una baja sin motivo toma el de la clase elegida.
     *
     * @return array<string, mixed>
     */
    public function calcularPago(string $clase, PlanillaEmpleado $empleado, ?int $salvoPlanilla = null): array
    {
        $motivo = self::motivoDeClase($clase);
        $deBaja = $this->motivoDeBaja($empleado);

        if ($deBaja !== null && $deBaja !== $motivo) {
            return ['motivo' => $deBaja, 'fuera' => 'la baja fue por «'.self::MOTIVOS[$deBaja].'» y este pago es de '
                .self::nombre((string) $motivo), 'falta' => null, 'avisar' => true, 'centavos' => 0, 'concepto' => null, 'nota' => null];
        }

        return $this->calcular($empleado, $motivo, $salvoPlanilla);
    }

    /**
     * Lo que necesita el recibo para firmar: título, concepto y tiempo de servicio. El tiempo
     * es el que quedó escrito al confirmar; sin confirmar, el de la ficha.
     *
     * @return array{titulo: string, concepto: string, tiempo: string, desde: CarbonImmutable, hasta: CarbonImmutable}|null
     */
    public function delRecibo(Planilla $planilla, PlanillaDetalle $detalle): ?array
    {
        $servicio = $this->servicioDeLinea($detalle);

        if (! self::es($planilla->clase) || $servicio === null) {
            return null;
        }

        $renuncia = $planilla->clase === self::CLASES['renuncia'];

        return [
            'titulo' => $renuncia ? 'Recibo de prestación por renuncia voluntaria' : 'Recibo de indemnización',
            'concepto' => $renuncia ? 'prestación económica por renuncia voluntaria' : 'indemnización por despido',
            'tiempo' => $this->tiempo($servicio['desde'], $servicio['hasta'])['texto'],
        ] + $servicio;
    }

    /**
     * Del ingreso a la baja: lo escrito en la línea al confirmar, o lo de la ficha.
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}|null
     */
    public function servicioDeLinea(PlanillaDetalle $detalle): ?array
    {
        if ($detalle->periodo_desde !== null && $detalle->periodo_hasta !== null) {
            return [
                'desde' => CarbonImmutable::parse($detalle->periodo_desde->toDateString()),
                'hasta' => CarbonImmutable::parse($detalle->periodo_hasta->toDateString()),
            ];
        }

        $e = $detalle->empleado;

        return $e?->alta_el === null || $e->baja_el === null ? null
            : ['desde' => CarbonImmutable::parse($e->alta_el->toDateString()), 'hasta' => $this->baja($e)];
    }

    /**
     * Años cumplidos y días de la fracción, del ingreso al día de baja incluido. Quien
     * entró el 01/08/2021 y se fue el 31/10/2026 lleva 5 años y 92 días.
     *
     * @return array{anios: int, dias: int, texto: string}
     */
    public function tiempo(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $fin = $hasta->addDay();
        $anios = $fin->year - $desde->year;

        if ($desde->addYearsNoOverflow($anios) > $fin) {
            $anios--;
        }

        $dias = (int) $desde->addYearsNoOverflow($anios)->diffInDays($fin);
        $partes = array_filter([
            $anios > 0 ? $anios.($anios === 1 ? ' año' : ' años') : null,
            $dias > 0 || $anios === 0 ? $dias.($dias === 1 ? ' día' : ' días') : null,
        ]);

        return ['anios' => $anios, 'dias' => $dias, 'texto' => implode(' y ', $partes)];
    }

    /** Lo mismo que dar de baja y pagar vacaciones: gestionar y ver importes. */
    public function autorizar(User $usuario): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar') && $usuario->can('planilla.salarios'), 403);
    }

    public function baja(PlanillaEmpleado $empleado): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $empleado->baja_el)->startOfDay();
    }

    /**
     * El detalle que queda en la línea y se imprime en el recibo. Sin el monto, que va aparte.
     *
     * @param  array<string, mixed>  $c
     */
    private function detalle(array $c): string
    {
        $regla = $c['regla'];

        return self::MOTIVOS[$c['motivo']].'. '.$c['tiempo']['texto'].' ('.$c['anios_decimal'].' años), del '
            .$c['desde']->format('d/m/Y').' al '.$c['hasta']->format('d/m/Y').'. Salario diario '
            .($c['con_tope']
                ? $c['diario_usado'].': el tope de '.$regla['tope_minimos'].' salarios mínimos diarios (el suyo es '.$c['diario'].').'
                : $c['diario'].' ('.Dinero::decimal($c['mensual']).' ÷ 30), sin pasar el tope de '.$regla['tope_minimos'].' salarios mínimos diarios.')
            .' '.($c['al_minimo']
                ? 'Le tocaban '.$c['dias_pagados'].' días: se pagan los '.$regla['minimo_dias'].' del mínimo.'
                : $regla['dias_por_anio'].' días por año: '.$c['dias_pagados'].' días.');
    }

    /** Un número de centavos ÷ algo, con decimales y la mitad hacia arriba: 40880 ÷ 30 = «13.6267». */
    private function conDecimales(int $centavos, int $divisor, int $decimales): string
    {
        $escala = 10 ** $decimales;
        $valor = $this->fraccion($centavos * $escala, $divisor * 100);

        return intdiv($valor, $escala).'.'.str_pad((string) ($valor % $escala), $decimales, '0', STR_PAD_LEFT);
    }

    /** Al centavo, la mitad hacia arriba: lo mismo que las vacaciones. */
    private function fraccion(int $numerador, int $denominador): int
    {
        return intdiv(2 * $numerador + $denominador, 2 * $denominador);
    }
}
