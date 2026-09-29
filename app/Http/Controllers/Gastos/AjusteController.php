<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\Gastos\Ajuste;
use App\Models\Gastos\Cuota;
use App\Services\Gastos\RegistrarAjuste;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notas de crédito, notas de débito y correcciones sobre una cuota.
 *
 * Un ajuste NO es un pago: no hay salida de dinero. Por eso vive en su propia
 * pantalla, con su propio permiso (`gastos.administrar`) y su propio motivo
 * obligatorio.
 */
class AjusteController extends Controller
{
    public function store(Request $request, Cuota $cuota, RegistrarAjuste $servicio)
    {
        $datos = $request->validate([
            'clave' => ['required', 'uuid'],
            'direccion' => ['required', Rule::in(Ajuste::DIRECCIONES)],
            'tipo' => ['required', Rule::in(array_keys(Ajuste::TIPOS))],
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'documento_recibido_id' => ['nullable', 'integer', Rule::exists('documentos_recibidos', 'id')],
        ]);

        $ajuste = $servicio->registrar($request->user(), $cuota, $datos);

        return redirect()->route('gastos.show', $cuota->gasto_id)
            ->with('gastos.aviso', $ajuste->etiquetaTipo().' registrada por '.$ajuste->importe.'.');
    }

    public function revertir(Request $request, Ajuste $ajuste, RegistrarAjuste $servicio)
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.min' => 'El motivo tiene que decir algo: revertir un ajuste altera la deuda.',
        ]);

        $servicio->revertir($request->user(), $ajuste, $datos['motivo']);

        return back()->with('gastos.aviso', 'Ajuste revertido. La deuda volvió a su importe anterior.');
    }
}
