<?php

namespace App\Http\Controllers\Planilla;

use App\Http\Controllers\Controller;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Dte\DtePdfService;
use App\Services\Planilla\ContratoTrabajo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Los datos del contrato de una persona y su impresión.
 *
 * Un contrato lleva el sueldo, así que todo acá exige `planilla.salarios` además de
 * `planilla.gestionar`, que ya pide la ruta.
 */
class PlanillaContratoController extends Controller
{
    public function edit(Request $request, PlanillaEmpleado $empleado, ContratoTrabajo $contrato)
    {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        return view('planilla.contrato', [
            'empleado' => $empleado,
            'faltantes' => $empleado->con_descuentos_ley ? $contrato->faltantes($empleado) : [],
            'avisos' => $contrato->avisos($empleado),
        ]);
    }

    public function update(Request $request, PlanillaEmpleado $empleado, ContratoTrabajo $contrato)
    {
        $contrato->guardarDatos($request->user(), $empleado, $request->all());

        return redirect()->route('planilla.empleados.contrato', $empleado)->with('planilla.aviso', 'Datos del contrato guardados.');
    }

    public function pdf(Request $request, PlanillaEmpleado $empleado, ContratoTrabajo $contrato, DtePdfService $pdf)
    {
        abort_unless($request->user()->can('planilla.salarios'), 403);

        $datos = $contrato->datos($empleado) + ['logo' => $pdf->logoSrc()];

        activity('empleado')->performedOn($empleado)->causedBy($request->user())->log('generó el contrato');

        return Pdf::loadView('planilla.contrato-pdf', $datos)
            ->setPaper('letter', 'portrait')->setOption('enable_font_subsetting', true)
            ->stream('contrato-'.Str::slug($empleado->nombre).'.pdf');
    }
}
