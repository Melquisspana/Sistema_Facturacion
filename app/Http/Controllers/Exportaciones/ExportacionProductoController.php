<?php

namespace App\Http\Controllers\Exportaciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\ExportacionProductoRequest;
use App\Models\ExportacionProducto;
use App\Services\Exportaciones\ImportadorCatalogoExportacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo de productos de EXPORTACIÓN (lista de empaque). CRUD + importación
 * desde la plantilla Excel. Independiente del catálogo de productos DTE.
 */
class ExportacionProductoController extends Controller
{
    public function index(Request $request): View
    {
        $productos = ExportacionProducto::query()
            // Para mostrar en qué clientes está asignado cada producto/presentación.
            ->with(['asignaciones' => fn ($q) => $q->where('activo', true), 'asignaciones.cliente:id,nombre'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('nombre_es', 'like', $buscar)
                    ->orWhere('nombre_en', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar));
            })
            ->when(! $request->boolean('inactivos'), fn ($q) => $q->where('activo', true))
            ->orderBy('nombre_es')
            ->paginate(25)
            ->withQueryString();

        return view('exportaciones.productos.index', ['productos' => $productos]);
    }

    public function create(): View
    {
        return view('exportaciones.productos.create');
    }

    public function store(ExportacionProductoRequest $request): RedirectResponse
    {
        ExportacionProducto::create($request->validated() + ['activo' => $request->boolean('activo', true)]);

        return redirect()
            ->route('exportaciones.productos.index')
            ->with('status', 'Producto de exportación creado.');
    }

    public function edit(ExportacionProducto $producto): View
    {
        return view('exportaciones.productos.edit', ['producto' => $producto]);
    }

    public function update(ExportacionProductoRequest $request, ExportacionProducto $producto): RedirectResponse
    {
        // Solo cambia el catálogo: los items ya agregados a exportaciones conservan su snapshot.
        $producto->update($request->validated() + ['activo' => $request->boolean('activo', $producto->activo)]);

        return redirect()
            ->route('exportaciones.productos.index')
            ->with('status', 'Producto de exportación actualizado.');
    }

    public function toggleActivo(ExportacionProducto $producto): RedirectResponse
    {
        $producto->update(['activo' => ! $producto->activo]);

        return back()->with('status', $producto->activo ? 'Producto activado.' : 'Producto desactivado.');
    }

    public function destroy(ExportacionProducto $producto): RedirectResponse
    {
        // Los items de exportaciones existentes conservan su snapshot (FK null on delete).
        $producto->delete();

        return redirect()
            ->route('exportaciones.productos.index')
            ->with('status', 'Producto de exportación eliminado.');
    }

    /** Formulario de importación del catálogo desde el Excel plantilla. */
    public function importarForm(): View
    {
        $plantilla = null;
        try {
            $plantilla = app(\App\Services\Exportaciones\ListaEmpaqueExcelService::class)->rutaPlantilla();
        } catch (\RuntimeException) {
            // Sin plantilla guardada: la vista lo indica y solo permite subir archivo.
        }

        return view('exportaciones.productos.importar', [
            'plantilla' => $plantilla,
            'totalProductos' => ExportacionProducto::count(),
        ]);
    }

    /** Importa desde el archivo subido o, si no se sube nada, desde la plantilla guardada. */
    public function importar(Request $request, ImportadorCatalogoExportacion $importador): RedirectResponse
    {
        $request->validate([
            'archivo' => ['nullable', 'file', 'mimes:xlsx', 'max:10240'],
        ], [], ['archivo' => 'archivo Excel']);

        $ruta = $request->hasFile('archivo')
            ? $request->file('archivo')->getRealPath()
            : null; // null = usar la plantilla guardada

        try {
            $resumen = $importador->importar($ruta);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $mensaje = "Importación completada: {$resumen['creados']} creados, {$resumen['omitidos']} omitidos (ya existían).";
        if ($resumen['errores'] !== []) {
            $mensaje .= ' Errores: '.implode(' | ', $resumen['errores']);
        }

        return redirect()
            ->route('exportaciones.productos.index')
            ->with($resumen['errores'] === [] ? 'status' : 'error', $mensaje);
    }
}
