<?php

namespace App\Http\Controllers\Planta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\ProductoBaseRequest;
use App\Models\Planta\PlantaProductoBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Qué se fabrica: la identidad del dulce, independiente del empaque.
 *
 * Sin relación alguna con el catálogo comercial de Facturación: no hay
 * `producto_id` ni forma de llegar a `productos` desde aquí.
 */
class ProductoBaseController extends Controller
{
    private const GESTIONAR = 'planta.catalogos.gestionar';

    public function index(Request $request): View
    {
        $productos = PlantaProductoBase::query()
            ->withCount('presentaciones')
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('nombre', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar));
            })
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('planta.productos-base.index', ['productos' => $productos]);
    }

    public function create(Request $request): View
    {
        $this->autorizarGestion($request);

        return view('planta.productos-base.create');
    }

    public function store(ProductoBaseRequest $request): RedirectResponse
    {
        $this->autorizarGestion($request);

        $producto = PlantaProductoBase::create($request->validated());

        return redirect()
            ->route('planta.productos-base.index')
            ->with('status', "Producto base «{$producto->nombre}» creado.");
    }

    public function edit(Request $request, PlantaProductoBase $productoBase): View
    {
        $this->autorizarGestion($request);

        return view('planta.productos-base.edit', ['producto' => $productoBase]);
    }

    public function update(ProductoBaseRequest $request, PlantaProductoBase $productoBase): RedirectResponse
    {
        $this->autorizarGestion($request);

        $productoBase->update($request->validated());

        return redirect()
            ->route('planta.productos-base.index')
            ->with('status', "Producto base «{$productoBase->nombre}» actualizado.");
    }

    public function toggleActivo(Request $request, PlantaProductoBase $productoBase): RedirectResponse
    {
        $this->autorizarGestion($request);

        $productoBase->update(['activo' => ! $productoBase->activo]);

        return back()->with(
            'status',
            $productoBase->activo
                ? "Producto base «{$productoBase->nombre}» activado."
                : "Producto base «{$productoBase->nombre}» desactivado."
        );
    }

    private function autorizarGestion(Request $request): void
    {
        abort_unless($request->user()?->can(self::GESTIONAR), 403);
    }
}
