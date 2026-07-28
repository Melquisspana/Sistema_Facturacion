<?php

namespace App\Http\Controllers\Planta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\ProveedorRequest;
use App\Models\Planta\PlantaProveedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo de proveedores de Planta. CRUD sin eliminación: la acción visible es
 * activar/desactivar.
 *
 * Catálogo PROPIO, sin relación con `clientes` ni con los documentos recibidos
 * de Facturación: aquí no hay integración contable ni crédito fiscal.
 */
class ProveedorController extends Controller
{
    private const GESTIONAR = 'planta.catalogos.gestionar';

    public function index(Request $request): View
    {
        $proveedores = PlantaProveedor::query()
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('nombre', 'like', $buscar)
                    ->orWhere('nombre_comercial', 'like', $buscar)
                    ->orWhere('contacto', 'like', $buscar));
            })
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('planta.proveedores.index', ['proveedores' => $proveedores]);
    }

    public function create(Request $request): View
    {
        $this->autorizarGestion($request);

        return view('planta.proveedores.create');
    }

    public function store(ProveedorRequest $request): RedirectResponse
    {
        $this->autorizarGestion($request);

        $proveedor = PlantaProveedor::create($request->validated());

        return redirect()
            ->route('planta.proveedores.index')
            ->with('status', "Proveedor «{$proveedor->nombre}» creado.");
    }

    public function edit(Request $request, PlantaProveedor $proveedor): View
    {
        $this->autorizarGestion($request);

        return view('planta.proveedores.edit', ['proveedor' => $proveedor]);
    }

    public function update(ProveedorRequest $request, PlantaProveedor $proveedor): RedirectResponse
    {
        $this->autorizarGestion($request);

        $proveedor->update($request->validated());

        return redirect()
            ->route('planta.proveedores.index')
            ->with('status', "Proveedor «{$proveedor->nombre}» actualizado.");
    }

    public function toggleActivo(Request $request, PlantaProveedor $proveedor): RedirectResponse
    {
        $this->autorizarGestion($request);

        $proveedor->update(['activo' => ! $proveedor->activo]);

        return back()->with(
            'status',
            $proveedor->activo ? "Proveedor «{$proveedor->nombre}» activado." : "Proveedor «{$proveedor->nombre}» desactivado."
        );
    }

    private function autorizarGestion(Request $request): void
    {
        abort_unless($request->user()?->can(self::GESTIONAR), 403);
    }
}
