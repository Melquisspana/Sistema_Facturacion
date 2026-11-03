<?php

namespace App\Http\Controllers\Planilla;

use App\Enums\RolSistema;
use App\Http\Controllers\Controller;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Gastos\Dinero;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use App\Services\Planilla\NombreUnico;
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
            'avisos' => $preparar->avisosParaConfirmar($planilla, true),
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

    public function nuevoEmpleado(Request $request, RegistrarEmpleado $registrar, AltaEmpleado $alta)
    {
        abort_unless($request->user()->activo && $request->user()->can('planilla.gestionar'), 403);

        return view('planilla.empleados-nuevo', [
            'candidatos' => $registrar->candidatos(),
            'coincidencias' => $alta->coincidencias((string) old('nombres', ''), (string) old('apellidos', '')),
            'roles' => RolSistema::opciones(),
        ]);
    }

    public function guardarEmpleado(Request $request, AltaEmpleado $alta)
    {
        $empleado = $alta->registrar($request->user(), $request->all());

        // Con descuentos lleva contrato: sus datos se piden una sola vez, en esa pantalla.
        // Quien no ve importes no puede abrirla: el contrato lleva el sueldo.
        $sigueConContrato = $empleado->con_descuentos_ley && $request->user()->can('planilla.salarios');

        return redirect()
            ->route($sigueConContrato ? 'planilla.empleados.contrato' : 'personal.empleados.show', $sigueConContrato ? $empleado : 'p'.$empleado->id)
            ->with('planilla.aviso', $empleado->nombre.' quedó en planilla.');
    }

    /** El sueldo nunca cambia solo: lo cambia una persona, con fecha. */
    public function cambiarSueldo(Request $request, PlanillaEmpleado $empleado, SueldoHabitual $sueldos)
    {
        $sueldos->registrarMensual($request->user(), $empleado, $request->only(['sueldo_mensual', 'vigente_desde', 'motivo', 'confirmar_bajo_minimo']));

        // Sin el importe: la bitácora la ve quien no tiene `planilla.salarios`.
        activity('empleado')->performedOn($empleado)->causedBy($request->user())->log('cambió el sueldo');

        return back()->with('planilla.aviso', 'Sueldo actualizado. Vale para las quincenas que empiecen desde esa fecha.');
    }

    public function cambiarNombre(Request $request, PlanillaEmpleado $empleado, NombreUnico $nombres)
    {
        $nombres->cambiar($request->user(), $empleado, $request->only('nombre'));

        activity('empleado')->performedOn($empleado)->causedBy($request->user())->log('corrigió el nombre');

        return back()->with('planilla.aviso', 'Nombre corregido en Planilla y Rutas.');
    }

    public function bajaEmpleado(Request $request, PlanillaEmpleado $empleado, BajaEmpleado $bajas)
    {
        $bajas->dar($request->user(), $empleado, (string) $request->input('fecha'));
        $mensaje = 'Empleado dado de baja. Su historial se conserva.';
        $huellas = $empleado->asistencia?->huellas()->where('activo', true)->count() ?? 0;
        if ($huellas > 0) {
            $mensaje .= ' Conserva '.$huellas.' huella(s) vigente(s): quitalas si ya no las necesita.';
        }

        return back()->with('planilla.aviso', $mensaje);
    }

    public function reactivarEmpleado(Request $request, PlanillaEmpleado $empleado, BajaEmpleado $bajas)
    {
        $bajas->reactivar($request->user(), $empleado);

        return back()->with('planilla.aviso', 'Empleado reactivado. Las huellas vigentes se conservan.');
    }
}
