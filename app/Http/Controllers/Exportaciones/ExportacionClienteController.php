<?php

namespace App\Http\Controllers\Exportaciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\ExportacionClienteRequest;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Clientes de exportación y su lista de precios/productos permitidos.
 * Regla: mismo producto con precio distinto = asignación con precio específico;
 * cambia empaque/unidades/pesos = otra presentación en el catálogo maestro.
 */
class ExportacionClienteController extends Controller
{
    public function index(Request $request): View
    {
        $clientes = ExportacionCliente::query()
            ->withCount(['productos as productos_count' => fn ($q) => $q->where('activo', true)])
            ->when($request->filled('q'), fn ($q) => $q->where('nombre', 'like', '%'.$request->string('q').'%'))
            ->when(! $request->boolean('inactivos'), fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('exportaciones.clientes.index', ['clientes' => $clientes]);
    }

    public function create(): View
    {
        return view('exportaciones.clientes.create');
    }

    public function store(ExportacionClienteRequest $request): RedirectResponse
    {
        $cliente = ExportacionCliente::create($request->validated() + ['activo' => $request->boolean('activo', true)]);

        return redirect()
            ->route('exportaciones.clientes.show', $cliente)
            ->with('status', 'Cliente de exportación creado. Ahora asignale productos y precios.');
    }

    /** Detalle del cliente: gestión de su lista de productos/precios. */
    public function show(ExportacionCliente $cliente): View
    {
        $cliente->load(['productos.producto:id,nombre_es,nombre_en,unidad,unidades_por_caja,precio_caja,activo']);

        $asignados = $cliente->productos->pluck('exportacion_producto_id');
        $disponibles = ExportacionProducto::where('activo', true)
            ->whereNotIn('id', $asignados)
            ->orderBy('nombre_es')
            ->get(['id', 'nombre_es', 'precio_caja']);

        return view('exportaciones.clientes.show', [
            'cliente' => $cliente,
            'disponibles' => $disponibles,
        ]);
    }

    public function edit(ExportacionCliente $cliente): View
    {
        return view('exportaciones.clientes.edit', ['cliente' => $cliente]);
    }

    public function update(ExportacionClienteRequest $request, ExportacionCliente $cliente): RedirectResponse
    {
        // Solo cambia el cliente: los encabezados de exportaciones ya creadas son snapshot.
        $cliente->update($request->validated() + ['activo' => $request->boolean('activo', $cliente->activo)]);

        return redirect()
            ->route('exportaciones.clientes.show', $cliente)
            ->with('status', 'Cliente de exportación actualizado.');
    }

    public function toggleActivo(ExportacionCliente $cliente): RedirectResponse
    {
        $cliente->update(['activo' => ! $cliente->activo]);

        return back()->with('status', $cliente->activo ? 'Cliente activado.' : 'Cliente desactivado.');
    }

    public function destroy(ExportacionCliente $cliente): RedirectResponse
    {
        // Exportaciones existentes conservan su snapshot (FK null on delete).
        $cliente->delete();

        return redirect()
            ->route('exportaciones.clientes.index')
            ->with('status', 'Cliente de exportación eliminado.');
    }

    /** Asigna un producto del catálogo al cliente con su precio específico. */
    public function storeProducto(Request $request, ExportacionCliente $cliente): RedirectResponse
    {
        $datos = $request->validate([
            'exportacion_producto_id' => [
                'required',
                'integer',
                Rule::exists('exportacion_productos', 'id'),
                // Unicidad cliente+producto (además del índice único en BD).
                Rule::unique('exportacion_cliente_productos', 'exportacion_producto_id')
                    ->where('exportacion_cliente_id', $cliente->id),
            ],
            'precio_caja' => ['required', 'numeric', 'min:0'],
        ], [
            'exportacion_producto_id.unique' => 'Ese producto ya está asignado a este cliente.',
        ], [
            'exportacion_producto_id' => 'producto',
            'precio_caja' => 'precio por caja',
        ]);

        $cliente->productos()->create($datos + ['activo' => true]);

        return redirect()
            ->route('exportaciones.clientes.show', $cliente)
            ->with('status', 'Producto asignado al cliente.');
    }

    /** Actualiza el precio específico o activa/desactiva la asignación. */
    public function updateProducto(Request $request, ExportacionCliente $cliente, ExportacionClienteProducto $asignacion): RedirectResponse
    {
        abort_unless($asignacion->exportacion_cliente_id === $cliente->id, 404);

        if ($request->has('toggle_activo')) {
            $asignacion->update(['activo' => ! $asignacion->activo]);

            return back()->with('status', $asignacion->activo ? 'Producto habilitado para el cliente.' : 'Producto deshabilitado para el cliente.');
        }

        $datos = $request->validate([
            'precio_caja' => ['required', 'numeric', 'min:0'],
        ], [], ['precio_caja' => 'precio por caja']);

        // Solo cambia la lista de precios: exportaciones ya creadas conservan su snapshot.
        $asignacion->update($datos);

        return back()->with('status', 'Precio actualizado.');
    }

    public function destroyProducto(ExportacionCliente $cliente, ExportacionClienteProducto $asignacion): RedirectResponse
    {
        abort_unless($asignacion->exportacion_cliente_id === $cliente->id, 404);
        $asignacion->delete();

        return back()->with('status', 'Producto quitado de la lista del cliente.');
    }

    /** Asigna de un golpe todo el catálogo activo que falte, usando el precio base. */
    public function asignarCatalogo(ExportacionCliente $cliente): RedirectResponse
    {
        $asignados = $cliente->productos()->pluck('exportacion_producto_id');
        $faltantes = ExportacionProducto::where('activo', true)
            ->whereNotIn('id', $asignados)
            ->get(['id', 'precio_caja']);

        $sinPrecioBase = 0;
        foreach ($faltantes as $producto) {
            if ($producto->precio_caja === null) {
                $sinPrecioBase++;

                continue;
            }
            $cliente->productos()->create([
                'exportacion_producto_id' => $producto->id,
                'precio_caja' => $producto->precio_caja,
                'activo' => true,
            ]);
        }

        $mensaje = 'Catálogo asignado: '.($faltantes->count() - $sinPrecioBase).' productos agregados con su precio base.';
        if ($sinPrecioBase > 0) {
            $mensaje .= " {$sinPrecioBase} sin precio base quedaron fuera: asignalos manualmente con precio.";
        }

        return back()->with('status', $mensaje);
    }
}
