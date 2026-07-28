<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\UnidadBase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\InsumoRequest;
use App\Models\Planta\PlantaInsumo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo de insumos de Planta. CRUD sin eliminación: la acción visible es
 * activar/desactivar, que conserva el historial.
 *
 * No toca inventario: los saldos, el libro mayor y los lotes llegan en pasos
 * posteriores. El registro en Activitylog lo hace el modelo con `LogsActivity`;
 * aquí no se duplica.
 */
class InsumoController extends Controller
{
    /** Permiso de escritura, reforzado en el controlador además del middleware. */
    private const GESTIONAR = 'planta.catalogos.gestionar';

    public function index(Request $request): View
    {
        $insumos = PlantaInsumo::query()
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('nombre', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar));
            })
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('planta.insumos.index', [
            'insumos' => $insumos,
            'tipos' => TipoInsumo::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->autorizarGestion($request);

        return view('planta.insumos.create', [
            'tipos' => TipoInsumo::cases(),
            'unidades' => UnidadBase::cases(),
        ]);
    }

    public function store(InsumoRequest $request): RedirectResponse
    {
        $this->autorizarGestion($request);

        $insumo = PlantaInsumo::create($request->validated());

        return redirect()
            ->route('planta.insumos.index')
            ->with('status', "Insumo «{$insumo->nombre}» creado.");
    }

    public function edit(Request $request, PlantaInsumo $insumo): View
    {
        $this->autorizarGestion($request);

        return view('planta.insumos.edit', [
            'insumo' => $insumo,
            'tipos' => TipoInsumo::cases(),
            'unidades' => UnidadBase::cases(),
        ]);
    }

    public function update(InsumoRequest $request, PlantaInsumo $insumo): RedirectResponse
    {
        $this->autorizarGestion($request);

        // Una sola escritura: no hace falta transacción.
        $insumo->update($request->validated());

        return redirect()
            ->route('planta.insumos.index')
            ->with('status', "Insumo «{$insumo->nombre}» actualizado.");
    }

    public function toggleActivo(Request $request, PlantaInsumo $insumo): RedirectResponse
    {
        $this->autorizarGestion($request);

        $insumo->update(['activo' => ! $insumo->activo]);

        return back()->with(
            'status',
            $insumo->activo ? "Insumo «{$insumo->nombre}» activado." : "Insumo «{$insumo->nombre}» desactivado."
        );
    }

    /** Defensa en profundidad: el middleware ya filtró, pero no se confía en una sola capa. */
    private function autorizarGestion(Request $request): void
    {
        abort_unless($request->user()?->can(self::GESTIONAR), 403);
    }
}
