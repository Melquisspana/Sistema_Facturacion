<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\RutaRequest;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\PropuestaRutas;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Catálogo de rutas. CRUD sin eliminación: la acción visible es activar/desactivar,
 * que conserva las salas asignadas y el historial de salidas.
 *
 * El detalle de una ruta ({@see show()}) es además la pantalla donde se administran
 * sus salas habituales; el alta/baja concreta la hace {@see RutaSalaController}.
 */
class RutaController extends Controller
{
    /**
     * «Configurar rutas»: todo lo que se toca de vez en cuando, en una sola página. Cada
     * ruta con su frecuencia, los lugares que cubre y las salas que la cobertura le
     * sugiere; y los vendedores. El usuario pidió menos pantallas (27/09/2026).
     */
    public function index(PropuestaRutas $propuestas): View
    {
        $clasificacion = $propuestas->clasificar();

        return view('rutas.rutas.index', [
            'rutas' => Ruta::query()
                ->with(['coberturas.departamento:id,nombre', 'coberturas.distrito:id,nombre,departamento_id', 'coberturas.distrito.departamento:id,nombre'])
                ->withCount('sucursales')
                ->orderByDesc('activa')
                ->orderBy('nombre')
                ->get(),
            // Salas sin ruta que la cobertura le propone a cada ruta: ruta_id => [sala_id, …].
            'sugeridas' => $clasificacion['proponer']
                ->groupBy(fn ($fila) => $fila['ruta']->id)
                ->map(fn ($filas) => $filas->map(fn ($fila) => $fila['sala']->id)->all()),
            'sinCobertura' => $clasificacion['sinCobertura'],
            'departamentos' => Departamento::orderBy('nombre')->get(['id', 'nombre']),
            'distritos' => Distrito::with('departamento:id,nombre')->orderBy('nombre')->get(['id', 'nombre', 'departamento_id'])
                ->groupBy(fn ($d) => $d->departamento?->nombre ?? '—')
                ->sortKeys(),
            'vendedores' => PersonalRuta::orderByDesc('activo')->orderBy('nombre')->get(['id', 'nombre', 'telefono', 'activo']),
        ]);
    }

    public function create(): View
    {
        return view('rutas.rutas.create');
    }

    public function store(RutaRequest $request): RedirectResponse
    {
        $ruta = Ruta::create($request->validated());

        if ($request->boolean('en_linea')) {
            return back()->with('status', "Ruta «{$ruta->nombre}» creada. Agregale los lugares que cubre.");
        }

        return redirect()
            ->route('rutas.rutas.show', $ruta)
            ->with('status', "Ruta «{$ruta->nombre}» creada.");
    }

    /**
     * Detalle de la ruta: sus salas, su cobertura y la última visita de cada sala. Las
     * salas se agregan con el buscador instantáneo ({@see RutaSalaController::buscar()}).
     */
    public function show(Ruta $ruta, EntregasCcf $entregas): View
    {
        $asignadas = $ruta->sucursales()
            ->with('cliente:id,nombre')
            ->orderBy('nombre')
            ->get();

        $coberturas = $ruta->coberturas()
            ->with(['departamento:id,nombre', 'distrito:id,nombre,departamento_id', 'distrito.departamento:id,nombre'])
            ->get()
            // Departamentos completos primero; después los distritos, por nombre.
            ->sortBy(fn ($c) => [$c->esDepartamento() ? 0 : 1, $c->etiqueta()])
            ->values();

        return view('rutas.rutas.show', [
            'ruta' => $ruta,
            'coberturas' => $coberturas,
            'ultimasVisitas' => $entregas->ultimaVisitaPorSala($asignadas->pluck('id')->all()),
            'departamentos' => Departamento::orderBy('nombre')->get(['id', 'nombre']),
            'distritos' => Distrito::with('departamento:id,nombre')->orderBy('nombre')->get(['id', 'nombre', 'departamento_id'])
                ->groupBy(fn ($d) => $d->departamento?->nombre ?? '—')
                ->sortKeys(),
            'asignadas' => $asignadas,
        ]);
    }

    public function edit(Ruta $ruta): View
    {
        return view('rutas.rutas.edit', ['ruta' => $ruta]);
    }

    public function update(RutaRequest $request, Ruta $ruta): RedirectResponse
    {
        $ruta->update($request->validated());

        if ($request->boolean('en_linea')) {
            return back()->with('status', "Ruta «{$ruta->nombre}» actualizada.");
        }

        return redirect()
            ->route('rutas.rutas.show', $ruta)
            ->with('status', "Ruta «{$ruta->nombre}» actualizada.");
    }

    public function toggleActiva(Ruta $ruta): RedirectResponse
    {
        $ruta->update(['activa' => ! $ruta->activa]);

        return back()->with(
            'status',
            $ruta->activa
                ? "Ruta «{$ruta->nombre}» activada."
                : "Ruta «{$ruta->nombre}» desactivada. Sus salas y su historial se conservan."
        );
    }
}
