<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\TipoUbicacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\UbicacionRequest;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo de ubicaciones de Planta. CRUD sin eliminación.
 *
 * Las ubicaciones de SISTEMA (`es_sistema = true`) tienen protecciones propias,
 * todas verificadas en backend: no se desactivan, no cambian de código y no
 * pierden la bandera. Las reglas de formulario viven en {@see UbicacionRequest};
 * aquí se cubre el toggle, que no pasa por ese Request.
 *
 * Las protecciones dependen de la BANDERA, no de códigos concretos: cuando el
 * seeder cree CASA, FABRICA y TRANSITO no habrá que tocar nada.
 */
class UbicacionController extends Controller
{
    private const GESTIONAR = 'planta.catalogos.gestionar';

    public function index(Request $request): View
    {
        $ubicaciones = PlantaUbicacion::query()
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('nombre', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar));
            })
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->orderBy('orden')
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('planta.ubicaciones.index', [
            'ubicaciones' => $ubicaciones,
            'tipos' => TipoUbicacion::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->autorizarGestion($request);

        return view('planta.ubicaciones.create', ['tipos' => TipoUbicacion::cases()]);
    }

    public function store(UbicacionRequest $request): RedirectResponse
    {
        $this->autorizarGestion($request);

        $ubicacion = PlantaUbicacion::create($request->validated());

        return redirect()
            ->route('planta.ubicaciones.index')
            ->with('status', "Ubicación «{$ubicacion->nombre}» creada.");
    }

    public function edit(Request $request, PlantaUbicacion $ubicacion): View
    {
        $this->autorizarGestion($request);

        return view('planta.ubicaciones.edit', [
            'ubicacion' => $ubicacion,
            'tipos' => TipoUbicacion::cases(),
        ]);
    }

    public function update(UbicacionRequest $request, PlantaUbicacion $ubicacion): RedirectResponse
    {
        $this->autorizarGestion($request);

        $ubicacion->update($request->validated());

        return redirect()
            ->route('planta.ubicaciones.index')
            ->with('status', "Ubicación «{$ubicacion->nombre}» actualizada.");
    }

    public function toggleActivo(Request $request, PlantaUbicacion $ubicacion): RedirectResponse
    {
        $this->autorizarGestion($request);

        // Una ubicación de sistema activa no se desactiva por ninguna vía.
        // Volver a activarla sí se permite: restaura el estado correcto.
        abort_if($ubicacion->es_sistema && $ubicacion->activo, 403, 'No se puede desactivar una ubicación de sistema.');

        $ubicacion->update(['activo' => ! $ubicacion->activo]);

        return back()->with(
            'status',
            $ubicacion->activo ? "Ubicación «{$ubicacion->nombre}» activada." : "Ubicación «{$ubicacion->nombre}» desactivada."
        );
    }

    private function autorizarGestion(Request $request): void
    {
        abort_unless($request->user()?->can(self::GESTIONAR), 403);
    }
}
