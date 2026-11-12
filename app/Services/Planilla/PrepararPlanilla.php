<?php

namespace App\Services\Planilla;

use App\Models\Gastos\Cuota;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaConcepto;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\SaldosGastos;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Preparar la planilla de un período: elegir a quién entra, con qué salario, qué
 * otros ingresos y qué descuentos.
 *
 * MIENTRAS ES BORRADOR NO DEBE NADA. No existe ninguna obligación, no hay nada que
 * pagar y cambiar un importe no tiene consecuencias. Eso es deliberado: una planilla
 * se arma a lo largo de varios días, con correcciones, y hasta que alguien la
 * confirma no puede haber deuda con nadie. La confirmación es un acto aparte.
 *
 * ISSS, AFP Y RENTA SE PROPONEN SOLOS para quien va con descuentos de ley, en la
 * quincena regular: {@see DescuentosLey} los calcula y una persona los revisa o los
 * corrige. Aguinaldo, Quincena 25 y vacaciones tienen su propia planilla
 * ({@see PrestacionAnual}), y la indemnización la suya, desde la baja
 * ({@see IndemnizacionBaja}); las horas extra no se calculan. En la
 * última quincena de junio y de diciembre la renta sale del recálculo del año.
 *
 * UNA PLANILLA POR PERÍODO. Lo garantiza el índice único
 * `(tipo_periodo, periodo, clase, individual_de)` en la base, no una comprobación previa.
 * `individual_de` es 0 en las planillas de varios; en vacaciones e indemnización, que se
 * pagan de a una persona, es esa persona, y por eso puede haber varios pagos en el mismo mes.
 */
final class PrepararPlanilla
{
    public function __construct(
        private PeriodoPlanilla $periodos,
        private TotalesPlanilla $totales,
        private SueldoHabitual $sueldos,
        private DescuentosLey $descuentos,
        private PrestacionAnual $prestaciones,
        private AcumuladoRenta $acumulados,
        private SaldosGastos $saldos,
        private RegistrarAnticipo $anticipos,
    ) {}

    /**
     * Abre el borrador de un período. Si ya existe, lo devuelve: pedir dos veces «la
     * quincena de septiembre» tiene que llevar a la misma planilla, no a un duplicado.
     *
     * @param  array<string, mixed>  $datos
     */
    public function abrir(User $usuario, array $datos): Planilla
    {
        $this->autorizar($usuario);

        $datos = Validator::make($datos, [
            'tipo_periodo' => ['required', Rule::in(array_keys(Planilla::TIPOS_PERIODO))],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'clase' => ['required', Rule::in(array_keys(Planilla::CLASES))],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
            'fecha_pago' => ['nullable', 'date_format:Y-m-d'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            // Solo en vacaciones e indemnización, que se pagan de a una persona.
            'empleado' => ['nullable', 'integer', Rule::exists('planilla_empleados', 'id')],
        ], [
            'fecha_pago.date_format' => 'La fecha de pago no es una fecha válida.',
            'empleado.integer' => 'No se encontró a esa persona en planilla.',
            'empleado.exists' => 'No se encontró a esa persona en planilla.',
        ])->validate();

        // Aguinaldo y Quincena 25 no pagan tiempo de un período: su fecha es la de pago.
        if ($this->prestaciones->es($datos['clase'])) {
            $this->prestaciones->exigirFechaDePago($datos['clase'], $datos['fecha_pago'] ?? null);
            $datos['tipo_periodo'] = 'mensual';
            $datos['fecha'] = $datos['fecha_pago'];
        }

        $vacaciones = $datos['clase'] === VacacionesAnuales::CLASE;
        if ($vacaciones && blank($datos['empleado'] ?? null)) {
            throw ValidationException::withMessages(['empleado' => 'Las vacaciones se pagan de a una persona: elegila en «Pagar vacaciones».']);
        }

        $indemnizacion = IndemnizacionBaja::es($datos['clase']);
        $alta = null;
        if ($indemnizacion) {
            $baja = blank($datos['empleado'] ?? null) ? null : PlanillaEmpleado::whereKey((int) $datos['empleado'])->value('baja_el');

            if ($baja === null) {
                throw ValidationException::withMessages(['empleado' => 'La indemnización se paga desde la baja de la persona: calculala en su ficha de Personal.']);
            }

            // Va en el día de la baja: un solo pago por baja ({@see IndemnizacionBaja::generada()}).
            $datos['fecha'] = CarbonImmutable::parse((string) $baja)->toDateString();
            $alta = PlanillaEmpleado::whereKey((int) $datos['empleado'])->first()?->alta_el?->toDateString();
        }

        // `individual_de` entra en la clave única: 0 en las planillas de varios.
        $individual = $vacaciones || $indemnizacion ? (int) $datos['empleado'] : 0;

        $periodo = $this->periodos->para($datos['tipo_periodo'], CarbonImmutable::parse($datos['fecha']));

        // La clave técnica y las fechas son el día de la baja: así va después de la última
        // quincena que cobró, en la lista y al borrar. Con el día, y no el mes, una
        // recontratación que se va el mismo mes que la relación anterior no choca con su
        // pago en la clave única: las dos bajas no pueden caer el mismo día. Anulado le
        // queda «2026-10-31-A123», que cabe en los 20 caracteres.
        if ($indemnizacion) {
            $periodo['periodo'] = $periodo['desde'] = $periodo['hasta'] = $datos['fecha'];
        }

        return DB::transaction(function () use ($usuario, $datos, $periodo, $individual, $alta) {
            // Ver {@see bloquearPersona()}. Si quien llama ya abrió una transacción, tiene que
            // bloquear antes que nada; acá se repite sin costo para quien llama directo.
            $persona = $individual > 0 ? $this->bloquearPersona($datos) : null;

            // Un pago individual sin su persona bloqueada no pasaría por sus comprobaciones.
            if ($individual > 0 && $persona === null) {
                throw ValidationException::withMessages(['empleado' => 'No se encontró a esa persona en planilla.']);
            }

            $existente = Planilla::where('tipo_periodo', $datos['tipo_periodo'])
                ->where('periodo', $periodo['periodo'])
                // La de la misma baja, sea despido o renuncia: se genera una sola vez.
                ->when(IndemnizacionBaja::es($datos['clase']),
                    fn ($q) => $q->whereIn('clase', IndemnizacionBaja::CLASES),
                    fn ($q) => $q->where('clase', $datos['clase']))
                // De esta relación laboral, como {@see IndemnizacionBaja::generada()}: el pago
                // de antes de una recontratación no es el de esta baja.
                ->when($alta !== null, fn ($q) => $q->whereDate('desde', '>=', $alta))
                ->where('individual_de', $individual)
                // Una anulada no ocupa el lugar: se puede hacer de nuevo. Un pago individual
                // dejó libre la clave única al anularse ({@see AnularPlanilla}); una quincena
                // o una mensual la deja ahora, al volver a hacerse ({@see liberarAnuladas()}).
                ->where('estado', '!=', 'anulada')
                ->first();

            if ($existente === null && $persona !== null && IndemnizacionBaja::es($datos['clase'])) {
                $this->exigirIndemnizacion($persona, $datos['clase'], CarbonImmutable::parse($datos['fecha_pago']), $datos['fecha']);
            } elseif ($existente === null && $persona !== null) {
                $this->exigirVacacionesPorPagar($persona, CarbonImmutable::parse($datos['fecha_pago']));
            }

            if ($existente !== null) {
                // Volver a abrir una quincena que ya existe la devuelve con lo que se
                // haya escrito. Pero si todavía está VACÍA se siembra igual: es el caso
                // de un borrador creado antes de que existiera la carga automática, y
                // dejarlo en blanco obligaría a escribir a mano justo lo que este
                // cambio venía a evitar. `precargarActivos` no toca una planilla que ya
                // tenga líneas.
                $this->precargarActivos($usuario, $existente);

                return $existente;
            }

            if ($individual === 0) {
                $this->liberarAnuladas($usuario, $datos['tipo_periodo'], $datos['clase'], $periodo);
            }

            try {
                $planilla = Planilla::create([
                    'clave' => (string) Str::uuid(),
                    'tipo_periodo' => $datos['tipo_periodo'],
                    'periodo' => $periodo['periodo'],
                    'clase' => $datos['clase'],
                    'individual_de' => $individual,
                    'desde' => $periodo['desde'],
                    'hasta' => $periodo['hasta'],
                    // Quien llama al servicio sin pasar por HTTP puede mandar '' (la regla
                    // `nullable` lo acepta); MySQL estricto no guarda '' en una fecha.
                    'fecha_pago' => blank($datos['fecha_pago'] ?? null) ? null : $datos['fecha_pago'],
                    'moneda' => $datos['moneda'],
                    'estado' => 'borrador',
                    'observaciones' => $datos['observaciones'] ?? null,
                    'registrado_por' => $usuario->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Otra pantalla la generó en el mismo instante: el índice único la frenó.
                throw ValidationException::withMessages(['periodo' => ($individual > 0 ? (IndemnizacionBaja::es($datos['clase']) ? 'Ese pago de indemnización' : 'Ese pago de vacaciones') : 'Esa planilla')
                    .' se acaba de generar desde otra pantalla. Buscalo en la lista de Planillas antes de intentarlo de nuevo.']);
            }

            $this->precargarActivos($usuario, $planilla);

            return $planilla;
        });
    }

    /**
     * Bloquea la ficha de la persona de un pago individual (vacaciones, indemnización), y
     * nada si no lo es.
     *
     * Dos pagos de la misma persona a la vez (dos pestañas, dos meses) se esperan: el
     * segundo ve el primero y no paga dos veces el mismo año. En MySQL (REPEATABLE READ)
     * la foto de lo que lee la transacción se toma en su primera lectura común, así que
     * este bloqueo tiene que ser LO PRIMERO de la transacción de afuera: por eso
     * {@see GenerarPlanilla} lo llama antes de abrir.
     *
     * @param  array<string, mixed>  $datos
     */
    public function bloquearPersona(array $datos): ?PlanillaEmpleado
    {
        $clase = (string) ($datos['clase'] ?? '');
        // El mismo entero que acepta la regla `integer` de {@see abrir()}: «+5» o « 5» también
        // son la persona 5, y tienen que bloquearla y pasar por sus comprobaciones.
        $id = is_scalar($datos['empleado'] ?? null)
            ? filter_var($datos['empleado'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (($clase !== VacacionesAnuales::CLASE && ! IndemnizacionBaja::es($clase)) || $id === false) {
            return null;
        }

        return PlanillaEmpleado::whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Deja libre el lugar de una quincena o una mensual anulada, para volver a hacerla.
     *
     * La anulada se queda como está —motivo, líneas, deudas extinguidas, pagos revertidos
     * y papeles firmados— y solo cambia su clave técnica a «2026-11-Q1-A{id}», como un
     * pago individual al anularse. En pantalla sigue siendo «2026-11-Q1»
     * ({@see Planilla::periodo()}).
     *
     * Solo lo frena una deuda de una anulada de ese período que siga VIVA en Gastos: con un
     * pago vigente o con saldo pendiente, porque alguien revirtió el ajuste que la dejó en
     * cero al anularla. Hacerla de nuevo dejaría dos deudas con la misma persona por la
     * misma quincena, y se le podría pagar dos veces. Un pago revertido no frena.
     *
     * @param  array{periodo: string, desde: string, hasta: string, etiqueta: string}  $periodo
     */
    private function liberarAnuladas(User $usuario, string $tipo, string $clase, array $periodo): void
    {
        // También las que ya dejaron el lugar. Primero SIN candado: casi nunca hay una, y en
        // MySQL un candado sobre filas que no existen toma un hueco del índice que dos
        // generaciones a la vez comparten; al insertar, cada una espera a la otra y MySQL
        // corta una (bloqueo mutuo) con un error 500 en vez del mensaje de {@see abrir()}.
        $ids = Planilla::where('tipo_periodo', $tipo)
            ->where(fn ($q) => $q->where('periodo', $periodo['periodo'])->orWhere('periodo', 'like', $periodo['periodo'].'-A%'))
            ->where('clase', $clase)
            ->where('individual_de', 0)
            ->where('estado', 'anulada')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Por id: bloquea solo esas filas. Releídas con el candado, por si otra pantalla
        // acaba de dejar libre o de borrar alguna.
        $anuladas = Planilla::whereKey($ids)->where('estado', 'anulada')->lockForUpdate()->get();

        $gastos = DB::table('planilla_detalles')->whereIn('planilla_id', $anuladas->modelKeys())->whereNotNull('gasto_id')->pluck('gasto_id')
            ->merge(DB::table('planilla_obligaciones_terceros')->whereIn('planilla_id', $anuladas->modelKeys())->whereNotNull('gasto_id')->pluck('gasto_id'));

        $conPagos = $pendientes = [];
        foreach (Cuota::whereIn('gasto_id', $gastos)->with('gasto:id,beneficiario')->get() as $cuota) {
            if ($this->saldos->aplicado($cuota->id) > 0) {
                $conPagos[] = $cuota->gasto->beneficiario;
            } elseif ($this->saldos->pendienteCuota($cuota) > 0) {
                $pendientes[] = $cuota->gasto->beneficiario;
            }
        }

        $quienes = function (array $nombres): string {
            $nombres = collect($nombres)->unique()->sort()->values();

            return $nombres->take(5)->implode(', ').($nombres->count() > 5 ? ' y otros' : '');
        };

        // Un pago a una anulada supone que se revirtió el ajuste de la anulación: al
        // revertir el pago, la deuda vuelve a quedar pendiente. Por eso se piden los dos pasos.
        if ($conPagos !== []) {
            throw ValidationException::withMessages([
                'periodo' => 'No se puede volver a hacer: la planilla '.$periodo['periodo'].' anulada tiene pagos sin revertir ('.$quienes($conPagos).'). '
                    .'Revertí primero esos pagos, cada uno con su motivo, y en Gastos dejá esas deudas otra vez en cero con un ajuste, '
                    .'como quedaron al anularla. Después volvé a generarla.',
            ]);
        }

        if ($pendientes !== []) {
            throw ValidationException::withMessages([
                'periodo' => 'No se puede volver a hacer: la planilla '.$periodo['periodo'].' anulada tiene deudas pendientes en Gastos ('.$quienes($pendientes).'), '
                    .'porque se revirtió el ajuste que las dejó en cero al anularla. '
                    .'Dejalas otra vez en cero con un ajuste, con su motivo, y después volvé a generarla.',
            ]);
        }

        // Solo una ocupa el lugar en la clave única; las de antes ya lo dejaron.
        $ocupa = $anuladas->first(fn (Planilla $p) => $p->getRawOriginal('periodo') === $periodo['periodo']);

        if ($ocupa === null) {
            return;
        }

        $ocupa->update(['periodo' => $periodo['periodo'].'-A'.$ocupa->id]);

        // Sin importes: la bitácora la ve quien no tiene `planilla.salarios`.
        activity('planilla')->performedOn($ocupa)->causedBy($usuario)
            ->withProperties(['periodo' => $periodo['periodo']])
            ->log('dejó libre su período para volver a hacerla');
    }

    /** Que la persona de un pago de vacaciones de verdad las tenga por pagar en esa fecha. */
    private function exigirVacacionesPorPagar(PlanillaEmpleado $empleado, CarbonImmutable $pago): void
    {
        // Lo mismo que el `max` del campo. Un pago con fecha de un mes que no llegó dejaría
        // un pago «del futuro» que frena borrar las quincenas de por medio. Con este tope
        // tampoco se puede pagar un año sin trabajar: el año que paga una fecha termina, a
        // más tardar, el fin de su mes, y el que se cumple este mes sí se paga.
        if ($pago > CarbonImmutable::now()->endOfMonth()) {
            throw ValidationException::withMessages([
                'fecha_pago' => 'La fecha de pago no puede ser de un mes que todavía no llegó.',
            ]);
        }

        $baja = $empleado->baja_el === null ? null : CarbonImmutable::parse((string) $empleado->baja_el)->toDateString();
        $motivo = ! $empleado->activo || ($baja !== null && $baja < $pago->startOfMonth()->toDateString())
            ? 'no está activa en planilla'
            : $this->prestaciones->calcular(VacacionesAnuales::CLASE, $empleado, null, $pago)['fuera'];

        if ($motivo !== null) {
            throw ValidationException::withMessages(['empleado' => $empleado->nombre.' no tiene vacaciones por pagar: '.$motivo.'.']);
        }
    }

    /**
     * Que a la persona de verdad le corresponda ESE pago por su baja, con la persona ya
     * bloqueada: dada de baja, con el motivo de la clase, sin otro pago vigente de la misma
     * baja y con todo lo que hace falta para calcularlo.
     */
    private function exigirIndemnizacion(PlanillaEmpleado $empleado, string $clase, CarbonImmutable $pago, string $baja): void
    {
        // Lo mismo que en vacaciones: un pago «del futuro» frenaría borrar las quincenas.
        if ($pago > CarbonImmutable::now()->endOfMonth()) {
            throw ValidationException::withMessages(['fecha_pago' => 'La fecha de pago no puede ser de un mes que todavía no llegó.']);
        }

        // La baja se leyó antes del bloqueo: si cambió a la vez, que no pase de largo.
        if ($empleado->baja_el === null || CarbonImmutable::parse((string) $empleado->baja_el)->toDateString() !== $baja) {
            throw ValidationException::withMessages(['empleado' => 'La baja de '.$empleado->nombre.' cambió mientras tanto. Volvé a calcularla desde su ficha.']);
        }

        if ($pago->toDateString() < $baja) {
            throw ValidationException::withMessages(['fecha_pago' => 'La fecha de pago no puede ser anterior a la baja ('.CarbonImmutable::parse($baja)->format('d/m/Y').').']);
        }

        $calculo = $this->prestaciones->calcular($clase, $empleado, null, $pago);

        if ($calculo['fuera'] !== null || $calculo['falta'] !== null) {
            throw ValidationException::withMessages(['empleado' => $empleado->nombre.': '
                .($calculo['fuera'] ?? 'falta '.$calculo['falta'].' para calcularlo').'.']);
        }
    }

    /**
     * Deja la quincena lista para REVISAR: las personas activas ya puestas, cada una
     * con el importe habitual que estaba vigente cuando empezó el período.
     *
     * Es el corazón de la simplificación. Antes, abrir una quincena daba una hoja en
     * blanco y había que volver a escribir a las mismas personas y sus mismos importes,
     * quince días tras quince días. Ahora lo normal es abrir, mirar y confirmar;
     * escribir es la excepción.
     *
     * ── Qué se trae y qué NO ──
     *
     * Se trae la persona y su importe habitual vigente. ISSS, AFP y renta los agrega
     * {@see guardarLineas()}, que es por donde pasa toda línea.
     *
     * Los extras y los demás descuentos **arrancan vacíos** en cada quincena, y es
     * deliberado: un extra que se copia solo termina pagándose dos veces, porque nadie
     * revisa lo que ya estaba ahí. Los anticipos tampoco se descuentan solos: se
     * muestran con su saldo para que se elija cuánto.
     *
     * ── Quién entra ──
     *
     * Quien está activo y no se fue antes de que empezara el período. Quien causó baja
     * el 05 no aparece en la quincena siguiente, y la del 1 al 15 en la que sí trabajó
     * queda intacta: las líneas guardadas son una foto, y esto solo decide qué se
     * PROPONE al abrir.
     *
     * ── Idempotencia ──
     *
     * Solo actúa sobre una planilla en borrador y sin líneas. Reabrir la misma quincena
     * devuelve la que ya existía, con lo que se haya escrito, y no siembra nada encima.
     */
    private function precargarActivos(User $usuario, Planilla $planilla): void
    {
        if (! $planilla->borrador() || PlanillaDetalle::where('planilla_id', $planilla->id)->exists()) {
            return;
        }

        $empleados = PlanillaEmpleado::query()
            // La indemnización es de quien ya se fue: no se mira si sigue activa.
            ->unless($planilla->esIndemnizacion(), fn ($q) => $q->where('activo', true)
                ->where(fn ($q) => $q->whereNull('baja_el')->orWhereDate('baja_el', '>=', $planilla->desde)))
            // Un pago individual (vacaciones, indemnización) es solo de su persona.
            ->when($planilla->individual_de > 0, fn ($q) => $q->whereKey($planilla->individual_de))
            ->orderBy('nombre')
            ->get();

        if ($empleados->isEmpty()) {
            return;
        }

        if ($this->prestaciones->es($planilla->clase)) {
            $this->guardarLineas($usuario, $planilla, $this->lineasDePrestacion($planilla, $empleados));

            return;
        }

        $habituales = $this->sueldos->vigentesEn($empleados, $planilla->desde->toDateString());

        $lineas = $empleados->map(fn (PlanillaEmpleado $e) => [
            'planilla_empleado_id' => $e->id,
            // Sin habitual registrado se propone 0.00 y la pantalla lo marca como algo
            // que falta. No se inventa un importe: que aparezca en blanco y cante es
            // mejor que un número que nadie escribió.
            'salario' => Dinero::decimal($habituales[$e->id] ?? 0),
            'conceptos' => [],
        ])->all();

        $this->guardarLineas($usuario, $planilla, $lineas);
    }

    /**
     * Una línea por persona, con el monto como ingreso: así se corrige a mano y sale en el
     * recibo como cualquier otro. El salario va en cero porque esto no paga tiempo. A quien
     * le falta un dato se le deja la línea vacía, y {@see reparosParaConfirmar()} lo frena.
     *
     * @param  Collection<int, PlanillaEmpleado>  $empleados
     * @return array<int, array<string, mixed>>
     */
    private function lineasDePrestacion(Planilla $planilla, Collection $empleados): array
    {
        $pago = $this->fechaDePago($planilla);
        $mensuales = $this->sueldos->mensualesEn($empleados, $pago->toDateString());
        $lineas = [];

        foreach ($empleados as $empleado) {
            $calculo = $this->prestaciones->calcular($planilla->clase, $empleado, $mensuales[$empleado->id] ?? null, $pago, $planilla->id);

            if ($calculo['fuera'] !== null) {
                continue;
            }

            $lineas[] = [
                'planilla_empleado_id' => $empleado->id,
                'salario' => '0.00',
                'conceptos' => $calculo['falta'] !== null ? [] : [[
                    'tipo' => 'ingreso',
                    'concepto' => $calculo['concepto'] ?? $this->prestaciones->concepto($planilla->clase, $pago),
                    'importe' => Dinero::decimal($calculo['centavos']),
                ]],
                // Las vacaciones de un aniversario de otro mes dicen cuándo fue.
                'observaciones' => $calculo['nota'] ?? null,
            ];
        }

        return $lineas;
    }

    private function fechaDePago(Planilla $planilla): CarbonImmutable
    {
        return CarbonImmutable::parse(($planilla->fecha_pago ?? $planilla->hasta)->toDateString());
    }

    /**
     * Guarda las líneas del borrador. Reemplaza lo que había: el formulario manda
     * SIEMPRE la planilla completa, así que quitar a alguien es no mandarlo.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function guardarLineas(User $usuario, Planilla $planilla, array $lineas): Planilla
    {
        $this->autorizar($usuario);
        $this->exigirBorrador($planilla);

        $lineas = $this->validar($lineas);

        // Un pago individual es de UNA persona, y es la suya: tampoco se agrega otra a mano.
        if ($planilla->esIndividual()) {
            $ids = array_map('intval', array_column($lineas, 'planilla_empleado_id'));

            if (count($ids) > 1 || ($planilla->individual_de > 0 && $ids !== [] && $ids !== [(int) $planilla->individual_de])) {
                throw ValidationException::withMessages([
                    'lineas' => $planilla->esVacaciones()
                        ? 'Un pago de vacaciones es de una sola persona. Para pagarle a otra, usá «Pagar vacaciones».'
                        : 'Un pago de indemnización es de una sola persona. El de otra se calcula desde su ficha.',
                ]);
            }

            // En vacaciones esas fechas son el año de servicio, y en la indemnización el
            // tiempo de servicio. Las escribe solo el sistema al confirmar
            // ({@see ConfirmarPlanilla}): nunca el formulario.
            $lineas = array_map(fn (array $l) => array_merge($l, ['periodo_desde' => null, 'periodo_hasta' => null]), $lineas);
        }

        return DB::transaction(function () use ($usuario, $planilla, $lineas) {
            $planilla = Planilla::whereKey($planilla->id)->lockForUpdate()->firstOrFail();
            // Otra vez, ya bloqueada: si se confirmó entre la primera mirada y el bloqueo,
            // borrar sus líneas se llevaría las de una planilla con deuda y anticipos aplicados.
            $this->exigirBorrador($planilla);
            $this->comprobarAnticipos($planilla, $lineas);
            $previos = $this->conceptosDeLeyGuardados($planilla);

            // Se rehace el borrador entero. Es seguro porque un borrador no tiene
            // obligaciones colgando: no hay ningún pago que pudiera quedar huérfano.
            PlanillaDetalle::where('planilla_id', $planilla->id)->delete();

            foreach ($lineas as $linea) {
                $empleado = PlanillaEmpleado::whereKey($linea['planilla_empleado_id'])->firstOrFail();
                $linea['conceptos'] = $this->conDescuentosDeLey($usuario, $planilla, $empleado, $linea, $previos[$empleado->id] ?? []);

                $totales = $this->totales->linea($linea['salario'], $linea['conceptos'] ?? []);

                $detalle = PlanillaDetalle::create([
                    'planilla_id' => $planilla->id,
                    'planilla_empleado_id' => $empleado->id,
                    // Fotografía: el recibo de este período no puede cambiar después.
                    'nombre_snapshot' => $empleado->nombre,
                    // El DUI también es foto: un documento firmado no puede cambiar
                    // porque alguien corrija la ficha tres meses después.
                    'dui_snapshot' => $empleado->dui,
                    'documento_tipo_snapshot' => $empleado->documento_tipo,
                    'cargo_snapshot' => $empleado->cargo,
                    'salario' => Dinero::decimal(Dinero::centavos($linea['salario'])),
                    // Período particular de quien entró o salió a mitad. Solo se guarda
                    // e imprime: el importe NO se calcula con estas fechas.
                    'periodo_desde' => $linea['periodo_desde'] ?? null,
                    'periodo_hasta' => $linea['periodo_hasta'] ?? null,
                    'total_ingresos' => Dinero::decimal($totales['total_ingresos']),
                    'descuentos' => Dinero::decimal($totales['descuentos']),
                    'a_pagar' => Dinero::decimal($totales['a_pagar']),
                    'observaciones' => $linea['observaciones'] ?? null,
                ]);

                foreach (array_values($linea['conceptos'] ?? []) as $orden => $concepto) {
                    if (trim((string) ($concepto['importe'] ?? '')) === '') {
                        continue;
                    }

                    PlanillaConcepto::create([
                        'planilla_detalle_id' => $detalle->id,
                        'tipo' => $concepto['tipo'],
                        'ley' => $concepto['ley'] ?? null,
                        'concepto' => trim($concepto['concepto']),
                        'importe' => Dinero::decimal(Dinero::centavos($concepto['importe'])),
                        'importe_calculado' => $concepto['importe_calculado'] ?? null,
                        // El destino solo significa algo en un descuento, y NO se rellena
                        // por defecto: queda NULL hasta que alguien elija cuál de los tres
                        // casos es. Adivinar «anticipo» era justamente lo ambiguo.
                        'destino' => $concepto['tipo'] === 'descuento'
                            ? (filled($concepto['destino'] ?? null) ? $concepto['destino'] : null)
                            : null,
                        'tercero' => $concepto['tipo'] === 'descuento' && ($concepto['destino'] ?? null) === 'tercero'
                            ? (trim((string) ($concepto['tercero'] ?? '')) ?: null)
                            : null,
                        // Qué anticipo era. La referencia escrita es descriptiva; lo que
                        // CONTROLA que no se recupere dos veces es el vínculo de abajo.
                        'referencia' => $concepto['tipo'] === 'descuento' && ($concepto['destino'] ?? null) === 'anticipo'
                            ? (trim((string) ($concepto['referencia'] ?? '')) ?: null)
                            : null,
                        // El anticipo CONCRETO que este descuento recupera. Sin él no hay
                        // forma de saber cuánto queda, y el mismo dinero se descontaría en
                        // la quincena siguiente sin que nada lo impidiera.
                        'planilla_anticipo_id' => $concepto['tipo'] === 'descuento' && ($concepto['destino'] ?? null) === 'anticipo'
                            ? ($concepto['planilla_anticipo_id'] ?? null) ?: null
                            : null,
                        'orden' => $orden,
                    ]);
                }
            }

            $planilla->touch();

            return $planilla->fresh();
        });
    }

    /**
     * Los conceptos de la línea, con ISSS, AFP y renta puestos por el servidor.
     *
     * El importe que manda el formulario no se cree: se compara con lo último que propuso
     * el sistema. Si es igual, nadie lo tocó y se reemplaza por la propuesta nueva, que
     * pudo cambiar con el salario. Si es distinto, alguien lo corrigió: se respeta y queda
     * constancia. Por eso sale de lo guardado y no de una marca que mande el navegador.
     *
     * Quien va sin descuentos no lleva ninguno, aunque el formulario los mande.
     *
     * @param  array<string, mixed>  $linea
     * @param  array<string, PlanillaConcepto>  $previos
     * @return array<int, array<string, mixed>>
     */
    private function conDescuentosDeLey(User $usuario, Planilla $planilla, PlanillaEmpleado $empleado, array $linea, array $previos): array
    {
        $conceptos = collect($linea['conceptos'] ?? []);
        $otros = $conceptos->reject(fn (array $c) => filled($c['ley'] ?? null))->values()->all();

        if (! $empleado->con_descuentos_ley || ! $this->descuentos->aplicaA($planilla)) {
            return $otros;
        }

        $base = $this->descuentos->baseDeLinea(Dinero::centavos($linea['salario']), $otros)['base'];
        $calculo = $this->descuentos->calcular($base, $planilla->desde->toDateString());

        // Sin parámetros vigentes no se pone nada: {@see reparosParaConfirmar()} lo frena.
        if ($calculo === null) {
            return $otros;
        }

        $entrantes = $conceptos->filter(fn (array $c) => filled($c['ley'] ?? null))->keyBy('ley');
        $deLey = [];

        foreach ($calculo['conceptos'] as $clave => $propuesta) {
            if ($clave === 'renta') {
                // ISSS y AFP ya quedaron arriba, con su corrección a mano si la hubo.
                $gravado = $base - array_sum(array_map(fn (array $c) => Dinero::centavos($c['importe']), $deLey));
                $propuesta['centavos'] = $this->rentaRecalculada($planilla, $empleado, $gravado)['centavos']
                    ?? $propuesta['centavos'];
            }

            $importe = $propuesta['centavos'];
            $escrito = trim((string) ($entrantes->get($clave)['importe'] ?? ''));

            if ($escrito !== '') {
                $previo = $previos[$clave] ?? null;
                $referencia = $previo?->importe_calculado !== null
                    ? Dinero::centavos((string) $previo->importe_calculado)
                    : $propuesta['centavos'];
                $escrito = Dinero::centavos($escrito);

                if ($escrito !== $referencia) {
                    $importe = $escrito;

                    if ($previo === null || Dinero::centavos((string) $previo->importe) !== $escrito) {
                        // Sin importes: la bitácora la ve quien no tiene `planilla.salarios`.
                        activity('planilla')->performedOn($planilla)->causedBy($usuario)
                            ->withProperties(['concepto' => $propuesta['concepto'], 'planilla_empleado_id' => $empleado->id])
                            ->log('corrigió a mano un descuento de ley');
                    }
                }
            }

            $deLey[] = [
                'tipo' => 'descuento',
                'ley' => $clave,
                'concepto' => $propuesta['concepto'],
                'importe' => Dinero::decimal($importe),
                'importe_calculado' => Dinero::decimal($propuesta['centavos']),
                'destino' => 'tercero',
                'tercero' => $propuesta['tercero'],
            ];
        }

        return array_merge($deLey, $otros);
    }

    /**
     * La renta de esta línea por el recálculo de junio o diciembre. Null si esta planilla
     * no lo lleva o si falta lo acumulado: entonces queda la renta de la quincena y
     * {@see reparosParaConfirmar()} frena la planilla.
     *
     * @param  int  $gravado  lo gravado de esta línea: base menos ISSS y AFP
     * @return array{centavos: int, segun_tabla: int, avisos: array<int, string>}|null
     */
    private function rentaRecalculada(Planilla $planilla, PlanillaEmpleado $empleado, int $gravado): ?array
    {
        if (! $this->descuentos->esRecalculo($planilla)) {
            return null;
        }

        $acumulado = $this->acumulados->paraRecalculo($empleado, $planilla);

        if (! $acumulado['listo']) {
            return null;
        }

        return $this->descuentos->recalcularRenta($acumulado['gravado'] + $gravado, $acumulado['renta'], $planilla->hasta->toDateString());
    }

    /** Lo gravado de una línea ya guardada: lo que cotiza menos el ISSS y la AFP que quedaron. */
    private function gravadoDe(PlanillaDetalle $detalle, int $base): int
    {
        return $base - $detalle->conceptos->whereIn('ley', ['isss', 'afp'])->sum(fn ($c) => Dinero::centavos((string) $c->importe));
    }

    /** @return array<int, array<string, PlanillaConcepto>> empleado_id => ley => concepto */
    private function conceptosDeLeyGuardados(Planilla $planilla): array
    {
        return PlanillaConcepto::whereNotNull('ley')
            ->whereHas('detalle', fn ($q) => $q->where('planilla_id', $planilla->id))
            ->with('detalle:id,planilla_empleado_id')
            ->get()
            ->groupBy(fn (PlanillaConcepto $c) => $c->detalle->planilla_empleado_id)
            ->map(fn ($conceptos) => $conceptos->keyBy('ley')->all())
            ->all();
    }

    /**
     * Quién de esta planilla ya está en otra no anulada con fechas cruzadas.
     *
     * @return Collection<int, object>
     */
    private function cruces(Planilla $planilla): Collection
    {
        // El aguinaldo no paga tiempo: que caiga en el mes de una quincena no es un cruce.
        if ($this->prestaciones->es($planilla->clase)) {
            return collect();
        }

        return DB::table('planilla_detalles as d')
            ->join('planillas as p', 'p.id', '=', 'd.planilla_id')
            ->whereIn('d.planilla_empleado_id', $planilla->detalles->pluck('planilla_empleado_id'))
            ->where('p.id', '!=', $planilla->id)
            ->where('p.estado', '!=', 'anulada')
            ->whereNotIn('p.clase', PrestacionAnual::CLASES)
            ->whereDate('p.desde', '<=', $planilla->hasta->toDateString())
            ->whereDate('p.hasta', '>=', $planilla->desde->toDateString())
            ->get(['d.nombre_snapshot', 'p.periodo', 'p.clase']);
    }

    /**
     * Lo que conviene mirar antes de confirmar, sin que lo frene.
     *
     * @return array<int, string>
     */
    public function avisosParaConfirmar(Planilla $planilla, bool $verImportes): array
    {
        $avisos = $this->pendientesDeRevisar($planilla, $verImportes);
        $regular = $planilla->clase === 'regular';

        foreach ($this->cruces($planilla) as $cruce) {
            if (! $regular || $cruce->clase !== 'regular') {
                $avisos[] = $cruce->nombre_snapshot.' ya está en la planilla '.$cruce->periodo.' de esas mismas fechas.';
            }
        }

        if ($this->prestaciones->es($planilla->clase)) {
            return array_merge($avisos, $this->avisosDePrestacion($planilla, $verImportes));
        }

        if (! $this->descuentos->aplicaA($planilla)) {
            return $avisos;
        }

        foreach ($planilla->detalles as $detalle) {
            if (! $detalle->empleado?->con_descuentos_ley) {
                continue;
            }

            foreach ($detalle->conceptos->filter->corregidoAMano() as $concepto) {
                $avisos[] = $detalle->nombre_snapshot.': '.$concepto->concepto.' corregido a mano'
                    .($verImportes ? ' (propuesta '.$concepto->importe_calculado.', queda '.$concepto->importe.').' : '.');
            }

            $base = $this->descuentos->baseDeLinea(Dinero::centavos((string) $detalle->salario), $detalle->conceptos->toArray());

            // En el recálculo, los avisos de renta son los del recálculo: los del tramo
            // quincenal hablarían de una cuenta que no se usó.
            if ($this->descuentos->esRecalculo($planilla)) {
                $recalculo = $this->rentaRecalculada($planilla, $detalle->empleado, $this->gravadoDe($detalle, $base['base']));
                $deRenta = $recalculo === null ? [] : array_merge(
                    ['La renta de esta quincena sale del recálculo de '.($planilla->hasta->month === 6 ? 'junio' : 'diciembre').' (Decreto 10).'],
                    $recalculo['avisos'],
                );
            } else {
                $deRenta = $this->descuentos->calcular($base['base'], $planilla->desde->toDateString())['avisos'] ?? [];
            }

            foreach (array_merge($base['avisos'], $deRenta) as $aviso) {
                $avisos[] = $detalle->nombre_snapshot.': '.$aviso;
            }
        }

        return $avisos;
    }

    /**
     * Lo que pide pasar por «Preparar» antes de confirmar, persona por persona. Con algo
     * de esto, {@see GenerarPlanilla} no confirma en un paso: deja el borrador.
     *
     * UN ANTICIPO CON SALDO. Generar pone a cada quien con su sueldo y sin descuentos, y el
     * anticipo solo se descuenta eligiéndolo en la línea. Sin este freno, quien tenía un
     * anticipo cobraba la quincena entera y el anticipo quedaba «por recuperar» para
     * siempre. Cuenta solo lo que esta planilla puede descontar: de esa persona, en su
     * moneda y de un pago vigente ({@see motivoContraAnticipo()}).
     *
     * @return array<int, string>
     */
    public function pendientesDeRevisar(Planilla $planilla, bool $verImportes): array
    {
        $pendientes = [];

        foreach ($planilla->detalles as $detalle) {
            $empleado = $detalle->empleado;

            if ($empleado === null) {
                continue;
            }

            // Con el descuento ya puesto en la línea no queda nada que avisar.
            $yaDescuenta = $detalle->conceptos->contains(fn (PlanillaConcepto $c) => $c->destino === 'anticipo' && $c->planilla_anticipo_id !== null);
            $saldo = $yaDescuenta ? 0 : $this->anticipos->disponibles($empleado)
                ->filter(fn (PlanillaAnticipo $a) => $this->motivoContraAnticipo($a, (int) $empleado->id, $planilla->moneda) === null)
                ->sum(fn (PlanillaAnticipo $a) => $a->pendiente());

            if ($saldo > 0) {
                // El importe solo lo ve quien ve importes.
                $pendientes[] = $detalle->nombre_snapshot.': tiene un anticipo por descontar'
                    .($verImportes ? ' de '.$planilla->moneda.' '.Dinero::mostrar($saldo) : '').'.';
            }
        }

        return $pendientes;
    }

    /** @return array<int, string> */
    private function avisosDePrestacion(Planilla $planilla, bool $verImportes): array
    {
        $avisos = [];
        $pago = $this->fechaDePago($planilla);
        $activos = PlanillaEmpleado::where('activo', true)->orderBy('nombre')->get();
        $mensuales = $this->sueldos->mensualesEn($activos, $pago->toDateString());

        foreach ($planilla->detalles as $detalle) {
            $ingresos = $detalle->conceptos->where('tipo', 'ingreso')->sum(fn ($c) => Dinero::centavos((string) $c->importe));
            $renta = $this->prestaciones->avisoDeRenta($planilla->clase, $ingresos, $pago);

            if ($renta !== null) {
                $avisos[] = $detalle->nombre_snapshot.': '.$renta;
            }
        }

        // Un pago individual es de su persona: quién más queda fuera no viene al caso.
        $otros = $planilla->individual_de > 0 ? collect() : $activos->whereNotIn('id', $planilla->detalles->pluck('planilla_empleado_id'));

        foreach ($otros as $empleado) {
            $calculo = $this->prestaciones->calcular($planilla->clase, $empleado, $mensuales[$empleado->id] ?? null, $pago, $planilla->id);
            $motivo = $calculo['fuera'];

            // En vacaciones, que ya haya cobrado o no cumpla el año es lo normal: no se avisa.
            if ($motivo !== null && ($calculo['avisar'] ?? true)) {
                // El motivo puede decir cuánto gana: solo lo ve quien ve importes.
                $avisos[] = $empleado->nombre.' queda fuera'.($verImportes ? ': '.$motivo : '').'.';
            }
        }

        return $avisos;
    }

    /**
     * Qué impide confirmar esta planilla, si algo lo impide. Se muestra mientras
     * todavía es un borrador, que es cuando sale barato arreglarlo.
     *
     * @return array<int, string>
     */
    public function reparosParaConfirmar(Planilla $planilla): array
    {
        $reparos = [];

        if ($planilla->detalles->isEmpty()) {
            $reparos[] = 'La planilla no tiene ni una persona.';
        }

        if ($planilla->esIndividual() && $planilla->detalles->count() > 1) {
            $reparos[] = 'Un pago '.($planilla->esVacaciones() ? 'de vacaciones' : 'de indemnización')
                .' es de una sola persona, y este tiene '.$planilla->detalles->count().'.';
        }

        foreach ($this->cruces($planilla) as $cruce) {
            // Una extraordinaria existe para pagar fuera de calendario: eso se avisa. Dos
            // regulares con las mismas fechas son la misma persona cobrando dos veces.
            if ($planilla->clase === 'regular' && $cruce->clase === 'regular') {
                $reparos[] = $cruce->nombre_snapshot.': ya está en la planilla '.$cruce->periodo.' con fechas que se cruzan con esta.';
            }
        }

        $calculaLey = $this->descuentos->aplicaA($planilla);
        $hayTasas = $this->descuentos->hayVigentes($planilla->desde->toDateString());

        if ($calculaLey && ! $hayTasas && $planilla->detalles->contains(fn ($d) => $d->empleado?->con_descuentos_ley)) {
            $reparos[] = 'No hay tasas de ISSS, AFP y renta vigentes al '.$planilla->desde->format('d/m/Y').'. Hay que cargarlas en la configuración antes de confirmar.';
        }

        if ($this->prestaciones->es($planilla->clase)) {
            $pago = $this->fechaDePago($planilla);
            $mensuales = $this->sueldos->mensualesEn($planilla->detalles->pluck('empleado')->filter(), $pago->toDateString());

            foreach ($planilla->detalles as $detalle) {
                $calculo = $this->prestaciones->calcular($planilla->clase, $detalle->empleado, $mensuales[$detalle->planilla_empleado_id] ?? null, $pago, $planilla->id);

                if ($calculo['falta'] !== null) {
                    $reparos[] = $detalle->nombre_snapshot.': falta '.$calculo['falta'].' para calcularlo.';
                }

                // Vacaciones de alguien agregado a mano que no las tiene por cobrar: es el
                // mismo año de servicio pagado dos veces, o uno que todavía no se cumplió.
                if ($planilla->clase === VacacionesAnuales::CLASE && $calculo['fuera'] !== null) {
                    $reparos[] = $detalle->nombre_snapshot.': no le tocan vacaciones en esta planilla ('.$calculo['fuera'].').';
                }

                // Lo mismo en la indemnización: otra baja, otro motivo o una ya generada.
                if ($planilla->esIndemnizacion() && $calculo['fuera'] !== null) {
                    $reparos[] = $detalle->nombre_snapshot.': no corresponde este pago ('.$calculo['fuera'].').';
                }

                // Un borrador de una baja que después se corrigió llevaría el monto de la fecha
                // vieja con el recibo de la nueva.
                $baja = $detalle->empleado?->baja_el;
                if ($planilla->esIndemnizacion() && ($baja === null || CarbonImmutable::parse((string) $baja)->toDateString() !== $planilla->desde->toDateString())) {
                    $reparos[] = 'La baja cambió desde que se generó este borrador: borralo y calculalo de nuevo.';
                }
            }
        }

        foreach ($planilla->detalles as $detalle) {
            $linea = $this->totales->deDetalle($detalle);

            if ($calculaLey && $hayTasas && $detalle->empleado?->con_descuentos_ley) {
                foreach (['isss' => 'ISSS', 'afp' => 'AFP'] as $clave => $nombre) {
                    $concepto = $detalle->conceptos->firstWhere('ley', $clave);

                    if ($concepto === null || Dinero::centavos((string) $concepto->importe) <= 0) {
                        $reparos[] = $detalle->nombre_snapshot.': falta el importe de '.$nombre.'.';
                    }
                }

                if ($this->descuentos->esRecalculo($planilla)) {
                    $falta = $this->acumulados->paraRecalculo($detalle->empleado, $planilla)['falta'];

                    if ($falta !== null) {
                        $reparos[] = $detalle->nombre_snapshot.': '.$falta.'.';
                    }
                }
            }

            if ($linea['a_pagar'] < 0) {
                $reparos[] = $detalle->nombre_snapshot.': los descuentos superan al total de ingresos.';
            }

            if ($linea['total_ingresos'] <= 0) {
                $reparos[] = $detalle->nombre_snapshot.': el total de ingresos es cero.';
            }

            foreach ($detalle->conceptos as $concepto) {
                if (! $concepto->esDescuento()) {
                    continue;
                }

                if (blank($concepto->destino)) {
                    $reparos[] = $detalle->nombre_snapshot.': falta decir qué es el descuento «'
                        .$concepto->concepto.'»: un anticipo ya pagado, algo que se le entrega a un tercero, u otro.';

                    continue;
                }

                if ($concepto->destino === 'tercero' && blank($concepto->tercero)) {
                    $reparos[] = $detalle->nombre_snapshot.': el descuento «'.$concepto->concepto
                        .'» se entrega a alguien más, pero no dice a quién.';
                }

                if ($concepto->destino === 'anticipo') {
                    // Escribir la referencia no basta: sin el vínculo, el mismo anticipo
                    // se puede descontar otra vez la quincena que viene y nada lo impide.
                    if ($concepto->planilla_anticipo_id === null) {
                        $reparos[] = $detalle->nombre_snapshot.': el descuento «'.$concepto->concepto
                            .'» dice ser un anticipo pero no está vinculado a ninguno. Registrá el anticipo y elegilo, '
                            .'para que se controle cuánto queda por recuperar.';

                        continue;
                    }

                    $anticipo = $concepto->anticipo;

                    // Se vuelve a mirar al confirmar: el pago pudo revertirse con el borrador guardado.
                    if ($motivo = $this->motivoContraAnticipo($anticipo, (int) $detalle->planilla_empleado_id, $planilla->moneda)) {
                        $reparos[] = $detalle->nombre_snapshot.': '.$motivo;

                        continue;
                    }

                    $recupera = Dinero::centavos((string) $concepto->importe);
                    // Lo ya aplicado por ESTE concepto no cuenta como pendiente ajeno.
                    $disponible = $anticipo->pendiente() + $this->yaAplicado($concepto->id);

                    if ($recupera > $disponible) {
                        $reparos[] = $detalle->nombre_snapshot.': del anticipo del '
                            .$anticipo->fecha->format('d/m/Y').' quedan '.Dinero::decimal($disponible)
                            .' por recuperar, y este descuento intenta recuperar '.Dinero::decimal($recupera).'.';
                    }
                }
            }
        }

        return $reparos;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array<int, array<string, mixed>>
     */
    private function validar(array $lineas): array
    {
        $datos = Validator::make(['lineas' => array_values($lineas)], [
            'lineas' => ['present', 'array', 'max:500'],
            'lineas.*.planilla_empleado_id' => ['required', 'integer', Rule::exists('planilla_empleados', 'id')],
            'lineas.*.salario' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            // Período particular: se guarda e imprime, no calcula nada. `after_or_equal`
            // impide el disparate de «trabajó del 15 al 8», que en el papel firmado
            // sería imposible de explicar.
            'lineas.*.periodo_desde' => ['nullable', 'date_format:Y-m-d'],
            'lineas.*.periodo_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:lineas.*.periodo_desde'],
            'lineas.*.observaciones' => ['nullable', 'string', 'max:500'],
            'lineas.*.conceptos' => ['nullable', 'array', 'max:30'],
            'lineas.*.conceptos.*.tipo' => ['required', Rule::in(array_keys(PlanillaConcepto::TIPOS))],
            'lineas.*.conceptos.*.ley' => ['nullable', Rule::in(['isss', 'afp', 'renta'])],
            'lineas.*.conceptos.*.concepto' => ['required', 'string', 'max:150'],
            'lineas.*.conceptos.*.importe' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'lineas.*.conceptos.*.destino' => ['nullable', Rule::in(array_keys(PlanillaConcepto::DESTINOS))],
            'lineas.*.conceptos.*.tercero' => ['nullable', 'string', 'max:180'],
            'lineas.*.conceptos.*.referencia' => ['nullable', 'string', 'max:180'],
            'lineas.*.conceptos.*.planilla_anticipo_id' => ['nullable', 'integer', Rule::exists('planilla_anticipos', 'id')],
        ], [
            'lineas.*.salario.required' => 'Cada persona necesita el salario del período.',
            'lineas.*.conceptos.*.concepto.required' => 'Escribí de qué es el ingreso o el descuento.',
        ])->validate();

        // La misma persona no puede entrar dos veces en la misma planilla. Lo impide
        // además el índice único; acá se avisa con un mensaje que se entiende.
        $ids = array_column($datos['lineas'], 'planilla_empleado_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'lineas' => 'Hay una persona repetida en la planilla. Cada quien entra una sola vez.',
            ]);
        }

        return $datos['lineas'];
    }

    private function exigirBorrador(Planilla $planilla): void
    {
        if (! $planilla->borrador()) {
            throw ValidationException::withMessages([
                'estado' => 'Esta planilla ya está '.mb_strtolower(Planilla::ESTADOS[$planilla->estado])
                    .' y no se edita. Para corregirla hay que anularla y explicar por qué.',
            ]);
        }
    }

    /**
     * Que cada anticipo que se descuenta pueda descontarse en ESTA línea. Que exista no
     * alcanza: el de otra persona, uno en otra moneda o uno cuyo pago se revirtió
     * recuperarían dinero que esta persona nunca recibió.
     *
     * @param  array<int, array<string, mixed>>  $lineas  ya validadas
     */
    private function comprobarAnticipos(Planilla $planilla, array $lineas): void
    {
        foreach ($lineas as $linea) {
            foreach ($linea['conceptos'] ?? [] as $concepto) {
                if ($concepto['tipo'] !== 'descuento' || ($concepto['destino'] ?? null) !== 'anticipo'
                    || blank($concepto['planilla_anticipo_id'] ?? null) || trim((string) ($concepto['importe'] ?? '')) === '') {
                    continue;
                }

                $anticipo = PlanillaAnticipo::findOrFail($concepto['planilla_anticipo_id']);

                if ($motivo = $this->motivoContraAnticipo($anticipo, (int) $linea['planilla_empleado_id'], $planilla->moneda)) {
                    // Con el nombre delante, como en los reparos: con veinte personas hay
                    // que saber qué línea corregir.
                    $nombre = PlanillaEmpleado::whereKey($linea['planilla_empleado_id'])->value('nombre');

                    throw ValidationException::withMessages(['lineas' => $nombre.': '.$motivo]);
                }
            }
        }
    }

    /**
     * Por qué este anticipo no se puede descontar a esta persona en esta moneda, si no se
     * puede. La usa también la pantalla, para no ofrecer lo que después se rechaza.
     */
    public function motivoContraAnticipo(PlanillaAnticipo $anticipo, int $empleadoId, string $moneda): ?string
    {
        $del = 'El anticipo del '.$anticipo->fecha->format('d/m/Y');

        if ((int) $anticipo->planilla_empleado_id !== $empleadoId) {
            return $del.' es de otra persona: solo se le descuenta a quien lo recibió.';
        }

        if ($anticipo->moneda !== $moneda) {
            return $del.' está en '.$anticipo->moneda.' y esta planilla en '.$moneda.': no se recupera en otra moneda.';
        }

        if ($anticipo->pago_id !== null && ! Pago::whereKey($anticipo->pago_id)->whereNull('revertido_at')->exists()) {
            return $del.' viene de un pago revertido: ese dinero no se entregó y no hay nada que recuperar.';
        }

        return null;
    }

    /** Lo que este mismo concepto ya tenía aplicado, para no contarlo como ajeno. */
    private function yaAplicado(int $conceptoId): int
    {
        $aplicado = DB::table('planilla_anticipo_aplicaciones')
            ->where('planilla_concepto_id', $conceptoId)
            ->value('importe');

        return $aplicado === null ? 0 : Dinero::centavos((string) $aplicado);
    }

    private function autorizar(User $usuario): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
    }
}
