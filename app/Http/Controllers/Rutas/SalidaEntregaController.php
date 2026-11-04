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
use Illuminate\Validation\ValidationException;

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

        return back()->with('status', 'El CCF volvió a quedar sin registrar.');
    }

    public function destroy(SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $entrega->setRelation('salida', $salida);
        $this->entregas->quitar($entrega);

        return back()->with('status', 'CCF quitado de la salida. Vuelve a quedar pendiente.');
    }

    /**
     * «Entregado antes de facturar»: el CCF que se facturó después de entregar queda
     * entregado en esta salida, sin dinero. Los errores van en su propia bolsa, para que no
     * abran ni rellenen los otros formularios de la hoja.
     */
    public function facturadoDespues(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $datos = $request->validateWithBag('facturadoDespues', [
            'numero_control' => ['required', 'string', 'max:60'],
            'ejercicio' => ['nullable', 'integer', 'min:2026', 'max:2100'],
            'entregado_por_id' => ['required', 'integer'],
            'fecha_entrega' => ['required', 'date_format:Y-m-d'],
            'nota' => ['nullable', 'string', 'max:300'],
        ], [
            'numero_control.required' => 'Escribí el número de control del CCF.',
            'entregado_por_id.required' => 'Elegí quién lo entregó.',
            'fecha_entrega.required' => 'Elegí qué día se entregó.',
            'fecha_entrega.date_format' => 'Elegí qué día se entregó.',
            'nota.max' => 'La nota puede tener hasta 300 letras.',
        ]);

        try {
            $entrega = $this->entregas->anotarFacturadoDespues($salida, $datos, $request->user());
        } catch (ValidationException $e) {
            throw $e->errorBag('facturadoDespues');
        }

        return back()->with('status', 'CCF '.$entrega->dte->numero_control.' anotado como entregado el '.$entrega->fecha_resultado->format('d/m/Y').', sin dinero.');
    }

    public function deshacerFacturadoDespues(SalidaRuta $salida, SalidaRutaEntrega $entrega): RedirectResponse
    {
        $this->entregas->deshacerFacturadoDespues($entrega);

        return back()->with('status', 'Registro deshecho: el CCF vuelve a quedar sin entregar.');
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
