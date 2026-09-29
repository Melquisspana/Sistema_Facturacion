<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaConcepto;
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

/**
 * Preparar la planilla de un período: elegir a quién entra, con qué salario, qué
 * otros ingresos y qué descuentos.
 *
 * MIENTRAS ES BORRADOR NO DEBE NADA. No existe ninguna obligación, no hay nada que
 * pagar y cambiar un importe no tiene consecuencias. Eso es deliberado: una planilla
 * se arma a lo largo de varios días, con correcciones, y hasta que alguien la
 * confirma no puede haber deuda con nadie. La confirmación es un acto aparte.
 *
 * NO CALCULA NADA LEGAL. Ni ISSS, ni AFP, ni renta, ni vacaciones, ni aguinaldo, ni
 * indemnización, ni horas extra. Cada importe lo escribe y lo revisa una persona.
 * Este servicio suma y resta lo que le dan, y nada más.
 *
 * UNA PLANILLA POR PERÍODO. Lo garantiza el índice único
 * `(tipo_periodo, periodo, clase)` en la base, no una comprobación previa.
 */
final class PrepararPlanilla
{
    public function __construct(
        private PeriodoPlanilla $periodos,
        private TotalesPlanilla $totales,
        private SueldoHabitual $sueldos,
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
        ])->validate();

        $periodo = $this->periodos->para($datos['tipo_periodo'], CarbonImmutable::parse($datos['fecha']));

        return DB::transaction(function () use ($usuario, $datos, $periodo) {
            $existente = Planilla::where('tipo_periodo', $datos['tipo_periodo'])
                ->where('periodo', $periodo['periodo'])
                ->where('clase', $datos['clase'])
                ->first();

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

            $planilla = Planilla::create([
                'clave' => (string) Str::uuid(),
                'tipo_periodo' => $datos['tipo_periodo'],
                'periodo' => $periodo['periodo'],
                'clase' => $datos['clase'],
                'desde' => $periodo['desde'],
                'hasta' => $periodo['hasta'],
                'fecha_pago' => $datos['fecha_pago'] ?? null,
                'moneda' => $datos['moneda'],
                'estado' => 'borrador',
                'observaciones' => $datos['observaciones'] ?? null,
                'registrado_por' => $usuario->id,
            ]);

            $this->precargarActivos($usuario, $planilla);

            return $planilla;
        });
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
     * Se trae la persona y su importe habitual vigente. Nada más.
     *
     * Extras y descuentos **arrancan vacíos** en cada quincena, y es deliberado: un
     * extra que se copia solo termina pagándose dos veces, porque nadie revisa lo que
     * ya estaba ahí. Los anticipos tampoco se descuentan solos: se muestran con su
     * saldo para que se elija cuánto.
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

        $empleados = PlanillaEmpleado::where('activo', true)
            ->where(fn ($q) => $q->whereNull('baja_el')->orWhereDate('baja_el', '>=', $planilla->desde))
            ->orderBy('nombre')
            ->get();

        if ($empleados->isEmpty()) {
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
     * Guarda las líneas del borrador. Reemplaza lo que había: el formulario manda
     * SIEMPRE la planilla completa, así que quitar a alguien es no mandarlo.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function guardarLineas(User $usuario, Planilla $planilla, array $lineas): Planilla
    {
        $this->autorizar($usuario);

        if (! $planilla->borrador()) {
            throw ValidationException::withMessages([
                'estado' => 'Esta planilla ya está '.mb_strtolower(Planilla::ESTADOS[$planilla->estado])
                    .' y no se edita. Para corregirla hay que anularla y explicar por qué.',
            ]);
        }

        $lineas = $this->validar($lineas);

        return DB::transaction(function () use ($planilla, $lineas) {
            $planilla = Planilla::whereKey($planilla->id)->lockForUpdate()->firstOrFail();

            // Se rehace el borrador entero. Es seguro porque un borrador no tiene
            // obligaciones colgando: no hay ningún pago que pudiera quedar huérfano.
            PlanillaDetalle::where('planilla_id', $planilla->id)->delete();

            foreach ($lineas as $linea) {
                $empleado = PlanillaEmpleado::whereKey($linea['planilla_empleado_id'])->firstOrFail();

                $totales = $this->totales->linea($linea['salario'], $linea['conceptos'] ?? []);

                $detalle = PlanillaDetalle::create([
                    'planilla_id' => $planilla->id,
                    'planilla_empleado_id' => $empleado->id,
                    // Fotografía: el recibo de este período no puede cambiar después.
                    'nombre_snapshot' => $empleado->nombre,
                    // El DUI también es foto: un documento firmado no puede cambiar
                    // porque alguien corrija la ficha tres meses después.
                    'dui_snapshot' => $empleado->dui,
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
                        'concepto' => trim($concepto['concepto']),
                        'importe' => Dinero::decimal(Dinero::centavos($concepto['importe'])),
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

        foreach ($planilla->detalles as $detalle) {
            $linea = $this->totales->deDetalle($detalle);

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
