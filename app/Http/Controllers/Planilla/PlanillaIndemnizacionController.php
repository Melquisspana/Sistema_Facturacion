<?php

namespace App\Http\Controllers\Planilla;

use App\Http\Controllers\Controller;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Planilla\IndemnizacionBaja;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Calcular indemnización» o «Calcular prestación por renuncia» de quien se fue. Solo
 * muestra el cálculo: el pago se genera por la misma revisión que vacaciones
 * ({@see PlanillaController::revisar()}). Los mismos permisos que dar de baja y que pagar
 * vacaciones: gestionar y ver importes.
 */
class PlanillaIndemnizacionController extends Controller
{
    public function calcular(Request $request, PlanillaEmpleado $empleado, IndemnizacionBaja $indemnizacion)
    {
        $indemnizacion->autorizar($request->user());

        if (($ya = $indemnizacion->generada($empleado)) !== null) {
            return redirect()->route('planilla.show', $ya)->with('planilla.aviso', 'El pago de esta baja ya se generó: es este.');
        }

        $ficha = route('personal.empleados.show', 'p'.$empleado->id);

        if ($empleado->activo || $empleado->baja_el === null) {
            return redirect($ficha)->with('planilla.aviso', 'Todavía está activa: la indemnización se calcula después de darle de baja.');
        }

        // Una baja de antes de que se preguntara el motivo lo elige acá.
        $deBaja = $indemnizacion->motivoDeBaja($empleado);
        $pedido = $request->validate(['motivo' => ['nullable', Rule::in(array_keys(IndemnizacionBaja::MOTIVOS))]])['motivo'] ?? null;
        $motivo = $deBaja ?? $pedido;
        $hoy = CarbonImmutable::now();

        return view('planilla.indemnizacion', [
            'empleado' => $empleado,
            'motivo' => $motivo,
            'elegir' => $deBaja === null,
            'calculo' => $motivo === null ? null : $indemnizacion->calcular($empleado, $motivo),
            'conDescuentos' => (bool) config('planilla_prestaciones.indemnizacion.con_descuentos_ley'),
            // Ni antes de la baja ni de un mes que todavía no llegó, igual que el servidor.
            'hoy' => max($hoy->toDateString(), $indemnizacion->baja($empleado)->toDateString()),
            'maxima' => $hoy->endOfMonth()->toDateString(),
            'moneda' => config('gastos.monedas')[0],
        ]);
    }
}
