<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\RutaRequest;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Ruta;
use App\Services\Rutas\PropuestaRutas;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * «Zonas»: el catálogo de rutas. Desde el 10/11/2026 una ruta es una zona: un nombre y los
 * lugares que cubre ({@see RutaCoberturaController}). Le pone nombre al viaje y agrupa el
 * tablero y el mapa. Ya no se asignan salas a mano ni se cargan días aproximados: las
 * columnas se conservan con sus datos, sin pantalla que las edite.
 *
 * Sin `destroy`: una zona no se elimina, se desactiva. Todo pasa en una sola página.
 */
class RutaController extends Controller
{
    public function index(PropuestaRutas $propuestas): View
    {
        return view('rutas.rutas.index', [
            'rutas' => Ruta::query()
                ->with(['coberturas.departamento:id,nombre', 'coberturas.distrito:id,nombre,departamento_id', 'coberturas.distrito.departamento:id,nombre'])
                ->orderByDesc('activa')
                ->orderBy('nombre')
                ->get(),
            // Las salas activas que ninguna zona cubre, por departamento: dice qué falta cubrir.
            'sinCobertura' => $propuestas->clasificar()['sinCobertura'],
            'departamentos' => Departamento::orderBy('nombre')->get(['id', 'nombre']),
            'distritos' => Distrito::with('departamento:id,nombre')->orderBy('nombre')->get(['id', 'nombre', 'departamento_id'])
                ->groupBy(fn ($d) => $d->departamento?->nombre ?? '—')
                ->sortKeys(),
        ]);
    }

    public function store(RutaRequest $request): RedirectResponse
    {
        $ruta = Ruta::create($request->validated());

        return redirect()->to(route('rutas.rutas.index').'#ruta-'.$ruta->id)
            ->with('status', "Zona «{$ruta->nombre}» creada. Agregale los lugares que cubre.");
    }

    public function update(RutaRequest $request, Ruta $ruta): RedirectResponse
    {
        $ruta->update($request->validated());

        return redirect()->to(route('rutas.rutas.index').'#ruta-'.$ruta->id)
            ->with('status', "Zona «{$ruta->nombre}» actualizada.");
    }

    public function toggleActiva(Ruta $ruta): RedirectResponse
    {
        $ruta->update(['activa' => ! $ruta->activa]);

        return back()->with(
            'status',
            $ruta->activa
                ? "Zona «{$ruta->nombre}» activada."
                : "Zona «{$ruta->nombre}» desactivada. Su historial se conserva."
        );
    }
}
