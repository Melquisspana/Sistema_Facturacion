<?php

namespace App\Http\Controllers\Planilla;

use App\Http\Controllers\Controller;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Gastos\Dinero;
use App\Services\Planilla\IdentidadNegocio;
use App\Services\Planilla\PeriodoPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use App\Services\Planilla\SueldoHabitual;
use App\Services\Planilla\TotalesPlanilla;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Planilla de control y recibos.
 *
 * `planilla.ver` deja entrar y ver QUÉ planillas existen, su período y su estado.
 * Los IMPORTES exigen `planilla.salarios` y se recortan en cada pantalla: es el
 * permiso confidencial del módulo. Preparar y confirmar exigen `planilla.gestionar`.
 */
class PlanillaController extends Controller
{
    public function index(Request $request, TotalesPlanilla $totales)
    {
        $verImportes = $request->user()->can('planilla.salarios');

        $planillas = Planilla::query()
            ->withCount('detalles')
            ->orderByDesc('desde')
            ->orderByDesc('id')
            ->paginate(20);

        return view('planilla.index', [
            'planillas' => $planillas,
            'verImportes' => $verImportes,
            'totales' => $totales,
        ]);
    }

    /** Elegir el período. Es el paso previo a preparar. */
    public function create(Request $request, PeriodoPlanilla $periodos)
    {
        abort_unless($request->user()->can('planilla.gestionar'), 403);

        $tipo = in_array($request->query('tipo'), array_keys(Planilla::TIPOS_PERIODO), true)
            ? $request->query('tipo')
            : 'quincenal';

        return view('planilla.create', [
            'tipo' => $tipo,
            'periodos' => $periodos->recientes($tipo, CarbonImmutable::now()),
            'hoy' => CarbonImmutable::now()->toDateString(),
        ]);
    }

    public function store(Request $request, PrepararPlanilla $preparar)
    {
        $planilla = $preparar->abrir($request->user(), $request->all());

        return redirect()->route('planilla.preparar', $planilla);
    }

    /**
     * EL FORMULARIO DE PREPARACIÓN. Una fila por persona, con su salario del período,
     * sus otros ingresos y sus descuentos; los totales se recalculan mientras se
     * escribe, para que nadie confirme una cifra que no vio.
     */
    public function preparar(
        Request $request,
        Planilla $planilla,
        PrepararPlanilla $preparar,
        TotalesPlanilla $totales,
        PeriodoPlanilla $periodos,
        RegistrarAnticipo $anticipos,
        SueldoHabitual $sueldos,
    ) {
        abort_unless($request->user()->can('planilla.gestionar'), 403);
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $planilla->load('detalles.conceptos', 'detalles.empleado');

        $disponibles = PlanillaEmpleado::where('activo', true)
            ->whereNotIn('id', $planilla->detalles->pluck('planilla_empleado_id'))
            ->where(fn ($q) => $q->whereNull('baja_el')->orWhereDate('baja_el', '>=', $planilla->desde))
            ->orderBy('nombre')
            ->get();

        return view('planilla.preparar', [
            'planilla' => $planilla,
            'totales' => $totales,
            'resumen' => $totales->dePlanilla($planilla),
            'reparos' => $preparar->reparosParaConfirmar($planilla),
            'periodos' => $periodos,
            // Quien todavía no está en esta planilla, para poder agregarlo.
            'disponibles' => $disponibles,
            // El habitual vigente al inicio del período, para mostrarlo como referencia
            // bajo el campo y para proponerlo a quien se agregue a mano. Cambiar el
            // importe de la quincena NO lo toca.
            'habituales' => $this->habitualesDe($sueldos, $planilla->detalles->pluck('empleado')->filter(), $planilla),
            'habitualesDisponibles' => $this->habitualesDe($sueldos, $disponibles, $planilla),
            // Los anticipos con saldo de cada persona. Un descuento de anticipo tiene
            // que apuntar a UNO de estos: escribir la referencia no controla nada.
            'anticipos' => $this->anticiposDisponibles($anticipos),
        ]);
    }

    /**
     * Anticipos con saldo, por persona, para que el formulario pueda ofrecerlos.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    /**
     * Importe habitual vigente al inicio del período, ya formateado, por persona.
     *
     * @param  Collection<int, PlanillaEmpleado>  $empleados
     * @return array<int, string>
     */
    private function habitualesDe(SueldoHabitual $sueldos, $empleados, Planilla $planilla): array
    {
        $empleados = $empleados->filter()->unique('id')->values();

        return array_map(
            fn (int $centavos) => Dinero::decimal($centavos),
            $sueldos->vigentesEn($empleados, $planilla->desde->toDateString())
        );
    }

    private function anticiposDisponibles(RegistrarAnticipo $anticipos): array
    {
        $salida = [];

        foreach (PlanillaEmpleado::where('activo', true)->get() as $empleado) {
            $salida[$empleado->id] = $anticipos->disponibles($empleado)->map(fn ($a) => [
                'id' => $a->id,
                'etiqueta' => $a->fecha->format('d/m/Y').' · '.$a->moneda.' '.$a->importe
                    .' · quedan '.Dinero::mostrar($a->pendiente()),
                'pendiente' => Dinero::decimal($a->pendiente()),
            ])->values()->all();
        }

        return $salida;
    }

    public function guardar(Request $request, Planilla $planilla, PrepararPlanilla $preparar)
    {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $preparar->guardarLineas($request->user(), $planilla, $request->input('lineas', []));

        return redirect()
            ->route('planilla.preparar', $planilla)
            ->with('planilla.aviso', 'Borrador guardado. Todavía no debe nada: las obligaciones se crean al confirmar.');
    }

    // ── Registro de personas ──────────────────────────────────────────────

    public function empleados(Request $request, RegistrarEmpleado $registrar)
    {
        return view('planilla.empleados', [
            'empleados' => PlanillaEmpleado::orderBy('nombre')->get(),
            'candidatos' => $request->user()->can('planilla.gestionar') ? $registrar->candidatos() : [],
            'verImportes' => $request->user()->can('planilla.salarios'),
        ]);
    }

    public function guardarEmpleado(Request $request, RegistrarEmpleado $registrar)
    {
        $datos = $request->all();

        // Si vino de la lista de personas que ya existen, el nombre no se vuelve a
        // escribir: se toma del módulo de origen.
        if (filled($datos['origen_tipo'] ?? null) && blank($datos['nombre'] ?? null)) {
            $datos['nombre'] = $registrar->nombreDe($datos['origen_tipo'], (int) $datos['origen_id']);
        }

        $empleado = $registrar->registrar($request->user(), $datos);

        return redirect()
            ->route('planilla.empleados')
            ->with('planilla.aviso', $empleado->nombre.' quedó en el registro de planilla ('.$empleado->origenIdentidad().').');
    }

    // ── Formatos ──────────────────────────────────────────────────────────

    /**
     * Vista previa de la hoja de firmas y del recibo, CON DATOS FICTICIOS.
     *
     * Existe para poder juzgar el formato antes de que haya una planilla de verdad, y
     * para revisarlo sin abrir los sueldos reales de nadie. Los nombres y los importes
     * son inventados y la pantalla lo dice en grande.
     */
    public function formatos(Request $request, TotalesPlanilla $totales, IdentidadNegocio $identidad)
    {
        abort_unless($request->user()->can('planilla.ver'), 403);

        return view('planilla.formatos', [
            'ficticia' => $this->datosFicticios(),
            'totales' => $totales,
            'formato' => $request->query('formato') === 'recibo' ? 'recibo' : 'hoja',
            // El encabezado real, con logo y empleadora: revisar el formato sin ellos
            // no diría nada sobre el papel que se va a imprimir.
            'negocio' => $identidad->negocio(),
            'empleadora' => $identidad->empleadora(),
            'logo' => asset('images/dte/logo-transparent.png'),
        ]);
    }

    /** @return array<string, mixed> */
    private function datosFicticios(): array
    {
        return [
            'periodo' => 'Primera quincena de septiembre de 2026 (01 al 15)',
            // Código corto del período: es lo que numera el recibo. La descripción
            // larga sirve para leerla, no para meterla en un folio.
            'codigo' => '2026-09-Q1',
            'periodo_corto' => '01–15 sep',
            'periodo_largo' => 'del 01/09/2026 al 15/09/2026',
            'desde' => '2026-09-01',
            'hasta' => '2026-09-15',
            'fecha_pago' => '16/09/2026',
            'moneda' => 'USD',
            // Quien entrega el pago es un dato del pago, no del negocio. En la vista
            // previa es inventado, igual que todo lo demás.
            'entrego' => 'Sandra Melgar Ayala',
            'lineas' => [
                [
                    'nombre' => 'Ana Beatriz Mendoza', 'cargo' => 'Ventas', 'dui' => '0123 4567-8',
                    'salario' => '225.00',
                    'ingresos' => [['concepto' => 'Comisión', 'importe' => '35.50']],
                    'descuentos' => [['concepto' => 'Anticipo de quincena', 'importe' => '40.00', 'destino' => 'anticipo', 'tercero' => null, 'referencia' => 'pago del 02/09, recibo 148']],
                ],
                [
                    'nombre' => 'Carlos Ernesto Rivas', 'cargo' => 'Reparto', 'dui' => '0234 5678-9',
                    'salario' => '200.00',
                    'ingresos' => [['concepto' => 'Viáticos', 'importe' => '20.00']],
                    'descuentos' => [['concepto' => 'Cuota de préstamo', 'importe' => '25.00', 'destino' => 'tercero', 'tercero' => 'Cooperativa La Esperanza', 'referencia' => null]],
                ],
                [
                    // Sin descuentos: en el recibo, ese apartado NO aparece.
                    'nombre' => 'María Fernanda Cruz', 'cargo' => 'Producción', 'dui' => '0567 8901-2',
                    'salario' => '187.50',
                    'ingresos' => [],
                    'descuentos' => [],
                ],
                [
                    // Entró a mitad de quincena y cobró a cuenta: las dos excepciones
                    // que el formato tiene que dejar claras.
                    'nombre' => 'Luis Alberto Portillo', 'cargo' => 'Producción', 'dui' => '0456 7890-1',
                    'salario' => '96.00',
                    'periodo' => '08–15 sep',
                    'periodo_largo' => 'del 08/09/2026 al 15/09/2026',
                    'pagado' => 6000,
                    'ingresos' => [],
                    'descuentos' => [],
                ],
                [
                    // Se le pagó dos días después que al resto.
                    'nombre' => 'Nubia Guadalupe Flores', 'cargo' => 'Empaque', 'dui' => '1012 3456-7',
                    'salario' => '165.00',
                    'fecha_pago_propia' => '18/09/2026',
                    'ingresos' => [],
                    'descuentos' => [],
                ],
            ],
        ];
    }
}
