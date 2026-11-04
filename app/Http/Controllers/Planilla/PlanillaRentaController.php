<?php

namespace App\Http\Controllers\Planilla;

use App\Http\Controllers\Controller;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaRentaAcumulado;
use App\Services\Planilla\AcumuladoRenta;
use Illuminate\Http\Request;

/**
 * Renta antes del sistema: lo gravado y lo retenido de cada persona en el año, en los
 * meses que no están en ninguna planilla. El recálculo de junio y diciembre lo necesita.
 *
 * Son sueldos: además de `planilla.gestionar` exige `planilla.salarios`, en la ruta y
 * acá.
 */
class PlanillaRentaController extends Controller
{
    public function index(Request $request, AcumuladoRenta $acumulados)
    {
        $this->autorizar($request);

        $hoy = now();
        $anios = range(2025, $hoy->year);
        $anio = in_array((int) $request->query('anio'), $anios, true) ? (int) $request->query('anio') : $hoy->year;

        // Cuando la persona no tiene ninguna quincena en el sistema ese año, se propone
        // hasta el recálculo que viene.
        $corte = $anio === $hoy->year && $hoy->toDateString() < $anio.'-06-16' ? $anio.'-06-16' : $anio.'-12-16';

        $confirmadas = PlanillaRentaAcumulado::where('anio', $anio)->with('registrador:id,name')->get()->keyBy('planilla_empleado_id');

        $personas = PlanillaEmpleado::where('con_descuentos_ley', true)
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('baja_el')->orWhereDate('baja_el', '>=', $anio.'-01-01'))
            ->orderBy('nombre')
            ->get()
            ->map(fn (PlanillaEmpleado $e) => [
                'empleado' => $e,
                'confirmada' => $confirmadas->get($e->id),
                'propuesta' => $acumulados->propuesta($e, $anio, $corte),
            ]);

        return view('planilla.renta', ['anio' => $anio, 'anios' => $anios, 'personas' => $personas]);
    }

    public function store(Request $request, AcumuladoRenta $acumulados)
    {
        $this->autorizar($request);

        $fila = $acumulados->confirmar($request->user(), $request->only([
            'planilla_empleado_id', 'anio', 'hasta', 'gravado', 'renta_retenida', 'observaciones',
        ]));

        return redirect()->route('planilla.renta.index', ['anio' => $fila->anio])
            ->with('status', 'Renta antes del sistema de '.$fila->empleado->nombre.' confirmada para '.$fila->anio.'.');
    }

    private function autorizar(Request $request): void
    {
        abort_unless($request->user()->can('planilla.gestionar') && $request->user()->can('planilla.salarios'), 403);
    }
}
