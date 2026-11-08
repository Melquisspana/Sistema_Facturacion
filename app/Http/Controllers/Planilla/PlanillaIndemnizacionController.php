<?php

namespace App\Http\Controllers\Planilla;

use App\Http\Controllers\Controller;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Planilla\IndemnizacionBaja;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Indemnización o prestación por renuncia de quien se fue: ver el cálculo y generarla.
 * Los mismos permisos que generar una planilla y ver sus importes.
 */
class PlanillaIndemnizacionController extends Controller
{
    /** No guarda nada: muestra el cálculo antes de generar. */
    public function revisar(Request $request, PlanillaEmpleado $empleado, IndemnizacionBaja $indemnizacion)
    {
        $indemnizacion->autorizar($request->user());

        if (($ya = $indemnizacion->generada($empleado)) !== null) {
            return redirect()->route('planilla.show', $ya)->with('planilla.aviso', 'La de esta baja ya se generó: es esta planilla.');
        }

        if ($empleado->activo || $empleado->baja_el === null) {
            return redirect()->route('personal.empleados.show', 'p'.$empleado->id)
                ->with('planilla.aviso', 'Todavía está activo: la indemnización se calcula después de darle de baja.');
        }

        // Una baja de antes de que se preguntara el motivo lo elige acá.
        $deBaja = $indemnizacion->motivoDeBaja($empleado);
        $pedido = $request->validate(['motivo' => ['nullable', Rule::in(array_keys(IndemnizacionBaja::MOTIVOS))]])['motivo'] ?? null;
        $motivo = $deBaja ?? $pedido;

        return view('planilla.indemnizacion', [
            'empleado' => $empleado,
            'motivo' => $motivo,
            'elegido' => $deBaja === null,
            'calculo' => $motivo === null ? null : $indemnizacion->calcular($empleado, $motivo),
            'conDescuentos' => (bool) config('planilla_prestaciones.indemnizacion.con_descuentos_ley'),
            'hoy' => now()->toDateString(),
        ]);
    }

    public function generar(Request $request, PlanillaEmpleado $empleado, IndemnizacionBaja $indemnizacion)
    {
        $planilla = $indemnizacion->generar($request->user(), $empleado, $request->only(['motivo', 'fecha_pago']));

        return redirect()->route('planilla.impresos', ['planilla' => $planilla, 'formato' => 'recibo'])
            ->with('planilla.aviso', 'Planilla '.$planilla->periodo.' generada. Imprimí el recibo y hacelo firmar.');
    }
}
