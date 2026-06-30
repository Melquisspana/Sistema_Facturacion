<?php

namespace App\Http\Controllers\Productos;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Productos\ProductoRequest;
use App\Models\Producto;
use App\Models\UnidadMedida;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductoController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Producto::class);

        $busqueda = trim((string) $request->input('q', ''));
        $tipoProducto = $request->input('tipo_producto');
        $activo = $request->input('activo');

        $productos = Producto::query()
            ->with('unidadMedida')
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $query->where(function ($w) use ($busqueda) {
                    $w->where('codigo', 'like', "%{$busqueda}%")
                        ->orWhere('codigo_barra', 'like', "%{$busqueda}%")
                        ->orWhere('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('descripcion', 'like', "%{$busqueda}%");
                });
            })
            ->when(TipoProducto::tryFrom((string) $tipoProducto), fn ($q) => $q->where('tipo_producto', $tipoProducto))
            ->when($activo === '1', fn ($q) => $q->where('activo', true))
            ->when($activo === '0', fn ($q) => $q->where('activo', false))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('productos.index', [
            'productos' => $productos,
            'filtros' => ['q' => $busqueda, 'tipo_producto' => $tipoProducto, 'activo' => $activo],
            'tiposProducto' => TipoProducto::opciones(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Producto::class);

        return view('productos.form', $this->datosFormulario(new Producto(['activo' => true, 'maneja_inventario' => false])));
    }

    public function store(ProductoRequest $request): RedirectResponse
    {
        $this->authorize('create', Producto::class);

        $producto = Producto::create($request->validated());

        return redirect()
            ->route('productos.show', $producto)
            ->with('status', 'Producto creado correctamente.');
    }

    public function show(Producto $producto): View
    {
        $this->authorize('view', $producto);

        $producto->load(['unidadMedida', 'preciosCliente.cliente', 'preciosCliente.clienteSucursal']);
        $actividades = $producto->activities()->with('causer')->latest()->limit(30)->get();

        // Clientes (con sus salas) para configurar precios especiales.
        $clientes = \App\Models\Cliente::where('activo', true)
            ->with(['sucursales' => fn ($q) => $q->where('activo', true)->orderBy('nombre')])
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return view('productos.show', compact('producto', 'actividades', 'clientes'));
    }

    public function edit(Producto $producto): View
    {
        $this->authorize('update', $producto);

        return view('productos.form', $this->datosFormulario($producto));
    }

    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        $this->authorize('update', $producto);

        $producto->update($request->validated());

        return redirect()
            ->route('productos.show', $producto)
            ->with('status', 'Producto actualizado correctamente.');
    }

    public function toggleActivo(Producto $producto): RedirectResponse
    {
        $this->authorize('update', $producto);

        $producto->update(['activo' => ! $producto->activo]);

        return back()->with('status', $producto->activo ? 'Producto activado.' : 'Producto inactivado.');
    }

    public function destroy(Producto $producto): RedirectResponse
    {
        $this->authorize('delete', $producto);

        $producto->delete(); // soft delete

        return redirect()
            ->route('productos.index')
            ->with('status', 'Producto eliminado.');
    }

    private function datosFormulario(Producto $producto): array
    {
        return [
            'producto' => $producto,
            'tiposProducto' => TipoProducto::opciones(),
            'tiposImpuesto' => TipoImpuesto::opciones(),
            'unidades' => UnidadMedida::where('activo', true)->orderBy('nombre')->get(),
        ];
    }
}
