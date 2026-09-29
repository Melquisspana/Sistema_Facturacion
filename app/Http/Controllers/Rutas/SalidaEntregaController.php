<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\OrigenRegistroEntrega;
use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Services\Rutas\EntregasCcf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Los CCF de una salida, desde la oficina: cargarlos, agregar o quitar uno, y registrar
 * qué pasó con cada uno. La regla vive en {@see EntregasCcf}; acá solo se traduce a
 * pantalla. Todo lo registrado desde acá queda con origen «oficina».
 */
class SalidaEntregaController extends Controller
{
    public function __construct(private readonly EntregasCcf $entregas) {}

    public function cargar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $salida->loadMissing('ruta');
        $agregados = $this->entregas->cargarPendientes($salida, $request->user());

        return back()->with('status', match ($agregados) {
            0 => 'No hay CCF pendientes nuevos para esta ruta.',
            1 => '1 CCF pendiente cargado a la salida.',
            default => "{$agregados} CCF pendientes cargados a la salida.",
        });
    }

    public function store(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $datos = $request->validate(['numero_control' => ['required', 'string', 'max:60']], [
            'numero_control.required' => 'Escribí el número de control del CCF.',
        ]);

        $entrega = $this->entregas->agregar($salida, $datos['numero_control'], $request->user());

        return back()->with('status', 'CCF '.$entrega->dte->numero_control.' agregado a la salida.');
    }

    public function update(Request $request, SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $entrega->setRelation('salida', $salida);

        $this->entregas->registrar(
            $entrega,
            $request->only(['resultado', 'entregado_por_id', 'trae_nota_averia', 'motivo_no_entrega', 'nota']),
            $request->user(),
            OrigenRegistroEntrega::Oficina,
        );

        return back()->with('status', 'Entrega registrada: '.$entrega->resultado->label().'.');
    }

    public function deshacer(SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $entrega->setRelation('salida', $salida);
        $this->entregas->deshacer($entrega);

        return back()->with('status', 'El CCF volvió a quedar sin registrar.');
    }

    public function destroy(SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $entrega->setRelation('salida', $salida);
        $this->entregas->quitar($entrega);

        return back()->with('status', 'CCF quitado de la salida. Vuelve a quedar pendiente.');
    }

    public function sala(Request $request, SalidaRuta $salida, ClienteSucursal $sucursal): RedirectResponse
    {
        $datos = $request->validate(['entregado_por_id' => ['required', 'integer']], [
            'entregado_por_id.required' => 'Elegí quién entregó.',
        ]);

        $marcados = $this->entregas->registrarSala($salida, $sucursal->id, (int) $datos['entregado_por_id'], $request->user(), OrigenRegistroEntrega::Oficina);

        return back()->with('status', "«{$sucursal->nombre}»: {$marcados} CCF marcados como entregados.");
    }
}
