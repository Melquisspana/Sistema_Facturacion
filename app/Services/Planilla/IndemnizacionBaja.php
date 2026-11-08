<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Indemnización por despido sin causa justificada y prestación por renuncia voluntaria.
 * Se pagan una sola vez, cuando la persona se va: salen de su baja, en una planilla de
 * clase «indemnizacion» de esa sola persona, que sigue el flujo de todas (confirmar,
 * pagar, anular, impresos y recibo). Parámetros en `config/planilla_prestaciones.php`.
 *
 * EL MOTIVO se elige al dar de baja y queda en la bitácora de la baja ({@see BajaEmpleado}).
 * La planilla lo lleva en su período: «IND-…» es despido y «REN-…», renuncia. El período
 * es «PREFIJO-persona-fecha de baja», así que el índice único de la base impide hacer
 * dos veces la misma; con otro motivo, lo impide {@see generar()}.
 *
 * LO QUE QUEDA EN LA LÍNEA, como foto que el recibo imprime: el tiempo de servicio en
 * `periodo_desde`–`periodo_hasta` y el detalle del cálculo en las observaciones.
 *
 * Todo en centavos enteros, y se redondea una sola vez, al centavo, la mitad hacia arriba.
 */
final class IndemnizacionBaja
{
    public const CLASE = 'indemnizacion';

    public const MOTIVOS = [
        'despido' => 'Despido sin causa justificada',
        'renuncia' => 'Renuncia voluntaria',
        'otro' => 'Otro motivo (con causa, fin de contrato…)',
    ];

    /** Los motivos que pagan algo, con el prefijo de su período. */
    private const PREFIJOS = ['despido' => 'IND', 'renuncia' => 'REN'];

    private const BAJA = 'dio de baja al empleado';

    public function __construct(
        private SueldoHabitual $sueldos,
        private SalarioMinimo $minimos,
        private PrepararPlanilla $preparar,
        private ConfirmarPlanilla $confirmar,
    ) {}

    /** «Calcular indemnización» o «Calcular prestación por renuncia». */
    public static function nombre(string $motivo): string
    {
        return $motivo === 'renuncia' ? 'prestación por renuncia voluntaria' : 'indemnización por despido';
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

    /** El motivo de una planilla de esta clase, por el prefijo de su período. */
    public function motivoDePlanilla(Planilla $planilla): ?string
    {
        if ($planilla->clase !== self::CLASE) {
            return null;
        }

        $motivo = array_search(Str::before($planilla->periodo, '-'), self::PREFIJOS, true);

        return $motivo === false ? null : $motivo;
    }

    /** La planilla no anulada de la baja actual de esta persona, si ya se generó. */
    public function generada(PlanillaEmpleado $empleado): ?Planilla
    {
        if ($empleado->baja_el === null) {
            return null;
        }

        return Planilla::where('clase', self::CLASE)
            ->where('estado', '!=', 'anulada')
            ->whereIn('periodo', array_map(fn (string $p) => $this->periodo($p, $empleado), self::PREFIJOS))
            ->first();
    }

    /**
     * Lo que le toca por esta baja, con cada paso del cálculo para mostrarlo.
     *
     * `fuera` = no le corresponde, y por qué. `falta` = falta un dato para calcularlo.
     *
     * @return array<string, mixed>
     */
    public function calcular(PlanillaEmpleado $empleado, string $motivo): array
    {
        $salida = ['motivo' => $motivo, 'fuera' => null, 'falta' => null, 'centavos' => 0];

        if (! isset(self::PREFIJOS[$motivo])) {
            return ['fuera' => 'Por el motivo de la baja no corresponde indemnización ni prestación por renuncia.'] + $salida;
        }

        if ($empleado->activo || $empleado->baja_el === null) {
            return ['fuera' => 'Todavía está activo: primero hay que darle de baja.'] + $salida;
        }

        if ($empleado->alta_el === null) {
            return ['falta' => 'la fecha de ingreso'] + $salida;
        }

        $p = config('planilla_prestaciones.indemnizacion');
        $regla = $p[$motivo];
        $desde = CarbonImmutable::parse($empleado->alta_el->toDateString());
        $hasta = CarbonImmutable::parse($empleado->baja_el);

        if ($hasta < $desde) {
            return ['falta' => 'una fecha de ingreso que no sea posterior a la baja'] + $salida;
        }

        $tiempo = $this->tiempo($desde, $hasta);
        $salida += ['desde' => $desde, 'hasta' => $hasta, 'tiempo' => $tiempo, 'regla' => $regla];

        if ($tiempo['anios'] < $regla['anios_minimos']) {
            return ['fuera' => 'La prestación por renuncia voluntaria pide al menos '.$regla['anios_minimos']
                .' años de servicio continuo, y tiene '.$tiempo['texto'].'.'] + $salida;
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
        $tope = $regla['tope_minimos'] * $minimo;
        // Mensual tope: el salario diario usado es este ÷ 30, sin redondear.
        $base = min($mensual, $tope);
        $alMinimo = $regla['dias_por_anio'] * $servicio < $regla['minimo_dias'] * $delAnio;

        $centavos = $alMinimo
            ? $this->fraccion($base * $regla['minimo_dias'], $delMes)
            : $this->fraccion($base * $regla['dias_por_anio'] * $servicio, $delMes * $delAnio);

        return [
            'centavos' => $centavos,
            'mensual' => $mensual,
            'diario' => $this->conDecimales($mensual, $delMes, 4),
            'tope_diario' => $this->conDecimales($tope, $delMes, 4),
            'con_tope' => $mensual > $tope,
            'diario_usado' => $this->conDecimales($base, $delMes, 4),
            'anios_decimal' => $this->conDecimales($servicio * 100, $delAnio, 4),
            'dias_pagados' => $this->conDecimales($regla['dias_por_anio'] * $servicio * 100, $delAnio, 2),
            'al_minimo' => $alMinimo,
        ] + $salida;
    }

    /**
     * Genera y confirma la planilla de esa sola persona, todo o nada.
     *
     * @param  array{motivo?: ?string, fecha_pago?: ?string}  $datos
     */
    public function generar(User $usuario, PlanillaEmpleado $empleado, array $datos): Planilla
    {
        $this->autorizar($usuario);

        $datos = Validator::make($datos, [
            'motivo' => ['nullable', Rule::in(array_keys(self::MOTIVOS))],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
        ], ['fecha_pago.required' => 'Indicá la fecha de pago.'])->validate();

        return DB::transaction(function () use ($usuario, $empleado, $datos) {
            // Bloqueada la persona, dos clics a la vez se serializan: el segundo ya la encuentra.
            $empleado = PlanillaEmpleado::whereKey($empleado->id)->lockForUpdate()->firstOrFail();
            $motivo = $this->motivo($empleado, $datos['motivo'] ?? null);
            $calculo = $this->calcular($empleado, $motivo);

            if ($calculo['fuera'] !== null || $calculo['falta'] !== null) {
                throw ValidationException::withMessages(['indemnizacion' => $calculo['fuera'] ?? 'Falta '.$calculo['falta'].' para calcularlo.']);
            }

            if (($ya = $this->generada($empleado)) !== null) {
                throw ValidationException::withMessages(['indemnizacion' => 'Ya se generó la '.self::nombre($this->motivoDePlanilla($ya)).' de esta baja: planilla '.$ya->periodo.'.']);
            }

            $periodo = $this->periodo(self::PREFIJOS[$motivo], $empleado);

            if (Planilla::where('periodo', $periodo)->where('clase', self::CLASE)->exists()) {
                throw ValidationException::withMessages(['indemnizacion' => 'La planilla '.$periodo.' quedó anulada. Para hacerla de nuevo, primero hay que borrarla.']);
            }

            $planilla = Planilla::create([
                'clave' => (string) Str::uuid(),
                'tipo_periodo' => 'mensual',
                'periodo' => $periodo,
                'clase' => self::CLASE,
                'desde' => $calculo['hasta']->toDateString(),
                'hasta' => $calculo['hasta']->toDateString(),
                'fecha_pago' => $datos['fecha_pago'],
                'moneda' => config('gastos.monedas')[0],
                'estado' => 'borrador',
                'registrado_por' => $usuario->id,
            ]);

            $this->preparar->guardarLineas($usuario, $planilla, [[
                'planilla_empleado_id' => $empleado->id,
                'salario' => '0.00',
                'periodo_desde' => $calculo['desde']->toDateString(),
                'periodo_hasta' => $calculo['hasta']->toDateString(),
                'conceptos' => [[
                    'tipo' => 'ingreso',
                    'concepto' => Str::ucfirst(self::nombre($motivo)),
                    'importe' => Dinero::decimal($calculo['centavos']),
                ]],
                'observaciones' => $this->detalle($calculo),
            ]]);

            // Sin importes: la bitácora la ve quien no tiene `planilla.salarios`.
            activity('planilla')->performedOn($planilla)->causedBy($usuario)
                ->withProperties(['motivo' => $motivo, 'planilla_empleado_id' => $empleado->id])
                ->log('generó la '.self::nombre($motivo));

            return $this->confirmar->confirmar($usuario, $planilla);
        });
    }

    /**
     * El motivo que vale: el de la bitácora de la baja. Solo una baja de antes de que se
     * preguntara lo toma de la pantalla.
     */
    public function motivo(PlanillaEmpleado $empleado, ?string $pedido): string
    {
        $motivo = $this->motivoDeBaja($empleado) ?? $pedido;

        if ($motivo === null) {
            throw ValidationException::withMessages(['motivo' => 'Elegí el motivo de la baja.']);
        }

        return $motivo;
    }

    /**
     * Lo que necesita el recibo para firmar: título, concepto y tiempo de servicio.
     *
     * @return array{titulo: string, concepto: string, tiempo: string, desde: CarbonImmutable, hasta: CarbonImmutable}|null
     */
    public function delRecibo(Planilla $planilla, PlanillaDetalle $detalle): ?array
    {
        $motivo = $this->motivoDePlanilla($planilla);

        if ($motivo === null || $detalle->periodo_desde === null || $detalle->periodo_hasta === null) {
            return null;
        }

        $desde = CarbonImmutable::parse($detalle->periodo_desde->toDateString());
        $hasta = CarbonImmutable::parse($detalle->periodo_hasta->toDateString());

        return [
            'titulo' => $motivo === 'renuncia' ? 'Recibo de prestación por renuncia voluntaria' : 'Recibo de indemnización',
            'concepto' => $motivo === 'renuncia' ? 'prestación económica por renuncia voluntaria' : 'indemnización por despido',
            'tiempo' => $this->tiempo($desde, $hasta)['texto'],
            'desde' => $desde,
            'hasta' => $hasta,
        ];
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

    /** El detalle que queda en la línea y se imprime en el recibo. Sin el monto: ese va aparte. */
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

    /** «IND-12-20261031»: cabe en los 20 caracteres del período. */
    private function periodo(string $prefijo, PlanillaEmpleado $empleado): string
    {
        return $prefijo.'-'.$empleado->id.'-'.CarbonImmutable::parse($empleado->baja_el)->format('Ymd');
    }

    /** Lo mismo que generar y confirmar una planilla, y ver sus importes. */
    public function autorizar(User $usuario): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar') && $usuario->can('planilla.salarios'), 403);
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
