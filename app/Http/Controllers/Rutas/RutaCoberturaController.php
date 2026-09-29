<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use App\Services\Rutas\PropuestaRutas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cobertura de una ruta: los departamentos completos y los distritos que atiende.
 *
 * Solo define la propuesta de ruta de las salas ({@see PropuestaRutas});
 * agregar o quitar un lugar NO mueve ninguna sala.
 */
class RutaCoberturaController extends Controller
{
    public function store(Request $request, Ruta $ruta): RedirectResponse
    {
        $datos = $request->validate([
            'departamento_id' => ['nullable', 'required_without:distrito_id', 'prohibits:distrito_id', Rule::exists('departamentos', 'id')],
            'distrito_id' => ['nullable', 'required_without:departamento_id', Rule::exists('distritos', 'id')],
        ], [
            'departamento_id.required_without' => 'Elegí un departamento o un distrito.',
            'distrito_id.required_without' => 'Elegí un departamento o un distrito.',
            'departamento_id.prohibits' => 'Agregá un departamento o un distrito, no los dos a la vez.',
        ]);

        $campo = filled($datos['departamento_id'] ?? null) ? 'departamento_id' : 'distrito_id';
        $lugar = $campo === 'departamento_id'
            ? Departamento::findOrFail($datos['departamento_id'])->nombre
            : Distrito::findOrFail($datos['distrito_id'])->nombre;

        // Un lugar es de una sola ruta. Se avisa con nombre y apellido en vez de dejar que el
        // índice único reviente: quien lo intenta necesita saber quién lo tiene.
        $existente = RutaCobertura::with('ruta:id,nombre')->where($campo, $datos[$campo])->first();
        if ($existente !== null) {
            return back()->with('error', $existente->ruta_id === $ruta->id
                ? "«{$lugar}» ya está en la cobertura de esta ruta."
                : "«{$lugar}» ya lo cubre la ruta «{$existente->ruta->nombre}». Quitalo de allá primero.");
        }

        $ruta->coberturas()->create([$campo => $datos[$campo]]);

        activity('ruta')
            ->performedOn($ruta)
            ->causedBy($request->user())
            ->withProperties([$campo => $datos[$campo], 'lugar' => $lugar])
            ->log('agregó a la cobertura de la ruta');

        return back()->with('status', "«{$lugar}» agregado a la cobertura. Las salas no se mueven solas: revisalas en «Asignar salas».");
    }

    public function destroy(Request $request, Ruta $ruta, RutaCobertura $cobertura): RedirectResponse
    {
        $cobertura->load('departamento:id,nombre', 'distrito.departamento:id,nombre');
        $lugar = $cobertura->etiqueta();
        $cobertura->delete();

        activity('ruta')
            ->performedOn($ruta)
            ->causedBy($request->user())
            ->withProperties(['lugar' => $lugar])
            ->log('quitó de la cobertura de la ruta');

        return back()->with('status', "«{$lugar}» ya no está en la cobertura. Las salas asignadas no cambiaron.");
    }
}
