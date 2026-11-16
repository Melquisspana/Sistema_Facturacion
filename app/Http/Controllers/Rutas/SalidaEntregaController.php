<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\OrigenRegistroEntrega;
use App\Http\Controllers\Controller;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Services\Rutas\EntregasCcf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Los CCF de una salida, desde la oficina: agregar o quitar uno, y registrar
 * qué pasó con cada uno. La regla vive en {@see EntregasCcf}; acá solo se traduce a
 * pantalla. Todo lo registrado desde acá queda con origen «oficina».
 */
class SalidaEntregaController extends Controller
{
    public function __construct(private readonly EntregasCcf $entregas) {}

    public function store(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $datos = $request->validate([
            'numero_control' => ['required', 'string', 'max:60'],
            'ejercicio' => ['nullable', 'integer', 'min:2026', 'max:2100'],
        ], [
            'numero_control.required' => 'Escribí el número de control del CCF.',
        ]);

        $entrega = $this->entregas->agregar($salida, $datos['numero_control'], $request->user(), isset($datos['ejercicio']) ? (int) $datos['ejercicio'] : null);

        return back()->with('status', 'CCF '.$entrega->dte->numero_control.' agregado a la salida.');
    }

    public function update(Request $request, SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $entrega->setRelation('salida', $salida);

        $this->entregas->registrar(
            $entrega,
            $request->only(['resultado', 'entregado_por_id', 'forma_cobro', 'trae_nota_averia', 'motivo_no_entrega', 'nota']),
            $request->user(),
            OrigenRegistroEntrega::Oficina,
        );

        return back()->with('status', 'Entrega registrada: '.$entrega->resultado->label().'.');
    }

    public function deshacer(SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $entrega->setRelation('salida', $salida);
        $this->entregas->deshacer($entrega);

        // En la automática la fila se borra (y si era la única, la salida se cancela).
        return back()->with('status', $salida->esAutomatica()
            ? 'Se quitó el CCF de la salida; vuelve a Por entregar.' : 'El CCF volvió a quedar sin registrar.');
    }

    /** «Corregir quién»: solo la persona, con motivo. La regla está en {@see EntregasCcf::corregirQuien()}. */
    public function corregirQuien(Request $request, SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $datos = $request->validate([
            'entregado_por_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:'.EntregasCcf::MOTIVO_QUIEN_MAX],
        ], [
            'entregado_por_id.required' => 'Elegí a la persona correcta.',
            'entregado_por_id.integer' => 'Elegí a la persona correcta.',
            'motivo.required' => 'Escribí el motivo del cambio.',
            'motivo.string' => 'Escribí el motivo del cambio.',
            'motivo.max' => 'El motivo puede tener hasta '.EntregasCcf::MOTIVO_QUIEN_MAX.' caracteres.',
        ]);
        $entrega->setRelation('salida', $salida);

        $entrega = $this->entregas->corregirQuien($entrega, (int) $datos['entregado_por_id'], $datos['motivo'], $request->user());

        return back()->with('status', 'Listo: ahora figura '.$entrega->entregadoPor->nombre.'. La fecha y el cobro no cambiaron.');
    }

    public function destroy(SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $entrega->setRelation('salida', $salida);
        $this->entregas->quitar($entrega);

        return back()->with('status', 'CCF quitado de la salida. Vuelve a quedar pendiente.');
    }
}
