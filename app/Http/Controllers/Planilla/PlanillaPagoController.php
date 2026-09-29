<?php

namespace App\Http\Controllers\Planilla;

use App\Http\Controllers\Controller;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Planilla\EstadoPlanilla;
use App\Services\Planilla\PagarPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pagos de planilla —individuales, por lote y parciales— y anticipos.
 *
 * Todo se apoya en Gastos: los pagos son pagos normales contra las obligaciones que la
 * planilla creó. Acá no vive ni un importe propio.
 */
class PlanillaPagoController extends Controller
{
    public function pagos(Request $request, Planilla $planilla, EstadoPlanilla $estado, PagarPlanilla $pagar)
    {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $planilla->load('detalles.gasto.cuotas', 'detalles.conceptos');

        return view('planilla.pagos', [
            'planilla' => $planilla,
            'estado' => $estado,
            'avance' => $estado->dePlanilla($planilla),
            'terceros' => PlanillaObligacionTercero::where('planilla_id', $planilla->id)->with('gasto.cuotas')->get(),
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'clave' => (string) Str::uuid(),
            'pagar' => $pagar,
            // Los pagos ya registrados contra las obligaciones de esta planilla, para
            // poder revertir desde acá sin salir del módulo.
            'pagosPorGasto' => $this->pagosPorGasto($planilla),
        ]);
    }

    /**
     * Pagos registrados contra cada obligación de la planilla, agrupados por gasto.
     *
     * Sale del propio Gastos: no hay una tabla de pagos de planilla, así que esto es
     * una LECTURA de lo que ya existe, no una copia.
     *
     * @return Collection<int, Collection>
     */
    private function pagosPorGasto(Planilla $planilla): Collection
    {
        $gastos = $planilla->detalles->pluck('gasto_id')->filter()
            ->merge(PlanillaObligacionTercero::where('planilla_id', $planilla->id)->pluck('gasto_id')->filter());

        if ($gastos->isEmpty()) {
            return collect();
        }

        return DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->whereIn('c.gasto_id', $gastos)
            ->orderByDesc('p.fecha')->orderByDesc('p.id')
            ->get(['c.gasto_id', 'p.id as pago_id', 'p.fecha', 'p.metodo', 'p.referencia',
                'a.importe', 'p.revertido_at', 'p.motivo_reversion'])
            ->groupBy('gasto_id');
    }

    public function pagarPersona(Request $request, PlanillaDetalle $detalle, PagarPlanilla $pagar)
    {
        $pagar->pagarPersona($request->user(), $detalle, $request->all());

        return back()->with('planilla.aviso', 'Pago registrado para '.$detalle->nombre_snapshot.'.');
    }

    public function pagarTercero(Request $request, PlanillaObligacionTercero $obligacion, PagarPlanilla $pagar)
    {
        $pagar->pagarTercero($request->user(), $obligacion, $request->all());

        return back()->with('planilla.aviso', 'Pago registrado para '.$obligacion->tercero.'.');
    }

    public function pagarLote(Request $request, Planilla $planilla, PagarPlanilla $pagar)
    {
        $resultado = $pagar->pagarLote(
            $request->user(),
            $planilla,
            $request->all(),
            array_map('intval', (array) $request->input('detalles', [])),
        );

        $aviso = $resultado['pagos'].' pago(s) registrados en el lote';
        if ($resultado['omitidos'] > 0) {
            // Se dice, porque es la señal de que el reintento funcionó como debía.
            $aviso .= '; '.$resultado['omitidos'].' se omitieron porque ya no tenían saldo';
        }

        return back()->with('planilla.aviso', $aviso.'.');
    }

    public function revertirPago(Request $request, Pago $pago, PagarPlanilla $pagar)
    {
        $pagar->revertirPago($request->user(), $pago, (string) $request->input('motivo', ''));

        return back()->with('planilla.aviso', 'Pago revertido. El saldo volvió a quedar pendiente y el historial conserva que existió.');
    }

    // ── Anticipos ─────────────────────────────────────────────────────────

    public function anticipos(Request $request, RegistrarAnticipo $anticipos)
    {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $empleados = PlanillaEmpleado::where('activo', true)->orderBy('nombre')->get();

        return view('planilla.anticipos', [
            'empleados' => $empleados,
            'anticipos' => PlanillaAnticipo::with('empleado', 'pago')->orderByDesc('fecha')->orderByDesc('id')->get(),
            'candidatosPorEmpleado' => $empleados->mapWithKeys(
                fn (PlanillaEmpleado $e) => [$e->id => $anticipos->pagosCandidatos($e)]
            ),
            'clave' => (string) Str::uuid(),
        ]);
    }

    public function guardarAnticipo(Request $request, PlanillaEmpleado $empleado, RegistrarAnticipo $anticipos)
    {
        $anticipo = $anticipos->registrar($request->user(), $empleado, $request->all());

        return redirect()
            ->route('planilla.anticipos')
            ->with('planilla.aviso', 'Anticipo de '.$anticipo->moneda.' '.$anticipo->importe.' registrado para '
                .$empleado->nombre.'. Quedan '.Dinero::mostrar($anticipo->pendiente()).' por recuperar.');
    }
}
