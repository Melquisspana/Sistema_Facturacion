<?php

namespace App\Http\Controllers\Planta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\PresentacionRequest;
use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaProductoBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cómo se presenta un producto base: «Coco 85 g».
 *
 * No es un producto fiscal y no tiene precio. La bolsa y la viñeta que le
 * corresponden viven en las configuraciones de empaque.
 */
class PresentacionController extends Controller
{
    private const GESTIONAR = 'planta.catalogos.gestionar';

    public function index(Request $request): View
    {
        $presentaciones = PlantaPresentacion::query()
            ->with('productoBase:id,codigo,nombre')
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('nombre', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar));
            })
            ->when($request->filled('producto_base'), fn ($q) => $q->where('planta_producto_base_id', $request->integer('producto_base')))
            ->when($request->filled('unidad'), fn ($q) => $q->where('unidad_contenido', $request->string('unidad')))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('planta.presentaciones.index', [
            'presentaciones' => $presentaciones,
            'productosBase' => $this->productosBaseParaFiltro(),
            'unidades' => PresentacionRequest::UNIDADES_CONTENIDO,
        ]);
    }

    public function create(Request $request): View
    {
        $this->autorizarGestion($request);

        return view('planta.presentaciones.create', [
            'productosBase' => $this->productosBaseSeleccionables(),
            'unidades' => PresentacionRequest::UNIDADES_CONTENIDO,
        ]);
    }

    public function store(PresentacionRequest $request): RedirectResponse
    {
        $this->autorizarGestion($request);

        $presentacion = PlantaPresentacion::create($request->validated());

        return redirect()
            ->route('planta.presentaciones.index')
            ->with('status', "Presentación «{$presentacion->nombre}» creada.");
    }

    public function edit(Request $request, PlantaPresentacion $presentacion): View
    {
        $this->autorizarGestion($request);

        return view('planta.presentaciones.edit', [
            'presentacion' => $presentacion,
            // Incluye el producto base histórico aunque haya quedado inactivo,
            // para que la ficha se pueda abrir y guardar sin cambiarlo.
            'productosBase' => $this->productosBaseSeleccionables($presentacion->planta_producto_base_id),
            'unidades' => PresentacionRequest::UNIDADES_CONTENIDO,
        ]);
    }

    public function update(PresentacionRequest $request, PlantaPresentacion $presentacion): RedirectResponse
    {
        $this->autorizarGestion($request);

        $presentacion->update($request->validated());

        return redirect()
            ->route('planta.presentaciones.index')
            ->with('status', "Presentación «{$presentacion->nombre}» actualizada.");
    }

    public function toggleActivo(Request $request, PlantaPresentacion $presentacion): RedirectResponse
    {
        $this->autorizarGestion($request);

        $presentacion->update(['activo' => ! $presentacion->activo]);

        return back()->with(
            'status',
            $presentacion->activo
                ? "Presentación «{$presentacion->nombre}» activada."
                : "Presentación «{$presentacion->nombre}» desactivada."
        );
    }

    /** Todos los productos base, para filtrar el listado. */
    private function productosBaseParaFiltro()
    {
        return PlantaProductoBase::query()->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'activo']);
    }

    /**
     * Productos base elegibles en el formulario: solo activos, más el histórico
     * que se esté editando aunque ya no lo esté.
     */
    private function productosBaseSeleccionables(?int $incluirId = null)
    {
        return PlantaProductoBase::query()
            ->where(fn ($q) => $q->where('activo', true)->when($incluirId, fn ($w) => $w->orWhere('id', $incluirId)))
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'activo']);
    }

    private function autorizarGestion(Request $request): void
    {
        abort_unless($request->user()?->can(self::GESTIONAR), 403);
    }
}
