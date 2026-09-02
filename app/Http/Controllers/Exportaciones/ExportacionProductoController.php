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
 * Catálogo de productos de EXPORTACIÓN.
 *
 * Vive bajo la entrada única «Productos», en la pestaña «De exportación». Sigue
 * siendo una tabla y un modelo distintos de los productos nacionales, y eso es
 * deliberado: un producto de exportación es una CAJA con nombre bilingüe, pesos
 * en kilos y libras y unidades por caja; uno nacional es una LÍNEA FISCAL con
 * catálogo del MH, unidad de medida oficial e inventario. Lo único que se unificó
 * es la puerta de entrada.
 *
 * BORRADO. Un producto con precios de cliente o con items de listas ya creadas NO
 * se borra: se DESACTIVA. La FK de `exportacion_cliente_productos` es
 * `cascadeOnDelete`, así que un borrado físico se llevaba por delante, en
 * silencio, los precios negociados con cada importador — y esos precios no se
 * pueden reconstruir desde el precio base. Ver {@see destroy()}.
 */
class ExportacionProductoController extends Controller
{
    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->input('q', ''));
        $activo = (string) $request->input('activo', '1');

        $productos = ExportacionProducto::query()
            // Para mostrar en qué clientes está asignado cada producto/presentación.
            ->with(['asignaciones' => fn ($q) => $q->where('activo', true), 'asignaciones.cliente:id,nombre'])
            ->withCount([
                'asignaciones as asignaciones_activas_count' => fn ($q) => $q->where('activo', true),
            ])
            ->when($busqueda !== '', function ($q) use ($busqueda) {
                $like = '%'.$busqueda.'%';
                $q->where(fn ($w) => $w->where('nombre_es', 'like', $like)
                    ->orWhere('nombre_en', 'like', $like)
                    ->orWhere('codigo', 'like', $like)
                    ->orWhere('unidad', 'like', $like));
            })
            // Tres estados explícitos: activos (por defecto), inactivos, todos. El
            // filtro anterior era una casilla «incluir inactivos» que no permitía ver
            // SOLO los archivados, que es justo lo que se necesita para revisarlos.
            ->when($activo === '1', fn ($q) => $q->where('activo', true))
            ->when($activo === '0', fn ($q) => $q->where('activo', false))
            ->orderBy('nombre_es')
            ->paginate(15)
            ->withQueryString();

        return view('productos.exportacion.index', [
            'productos' => $productos,
            'filtros' => ['q' => $busqueda, 'activo' => $activo],
            'totales' => [
                'activos' => ExportacionProducto::where('activo', true)->count(),
                'inactivos' => ExportacionProducto::where('activo', false)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('productos.exportacion.form', [
            'producto' => new ExportacionProducto(['activo' => true]),
        ]);
    }

    public function store(ExportacionProductoRequest $request): RedirectResponse
    {
        $producto = ExportacionProducto::create(
            $request->validated() + ['activo' => $request->boolean('activo', true)]
        );

        return redirect()
            ->route('productos.exportacion.show', $producto)
            ->with('status', 'Producto de exportación creado.');
    }

    /** Ficha del producto: sus datos de empaque y qué clientes lo compran y a qué precio. */
    public function show(ExportacionProducto $producto): View
    {
        $producto->load([
            'asignaciones.cliente.cliente:id,nombre',
            'asignaciones' => fn ($q) => $q->orderByDesc('activo'),
        ]);

        return view('productos.exportacion.show', [
            'producto' => $producto,
            'itemsCount' => $producto->items()->count(),
        ]);
    }

    public function edit(ExportacionProducto $producto): View
    {
        return view('productos.exportacion.form', ['producto' => $producto]);
    }

    public function update(ExportacionProductoRequest $request, ExportacionProducto $producto): RedirectResponse
    {
        // Solo cambia el catálogo: los items ya agregados a listas conservan su snapshot.
        $producto->update($request->validated() + ['activo' => $request->boolean('activo', $producto->activo)]);

        return redirect()
            ->route('productos.exportacion.show', $producto)
            ->with('status', 'Producto de exportación actualizado.');
    }

    /**
     * Archivar / reactivar. `activo = false` es el archivado: el producto desaparece
     * de los formularios de lista nueva pero conserva TODO su histórico —sus precios
     * por cliente, sus items en listas pasadas y su ficha—, y se puede reactivar sin
     * volver a cargar nada.
     */
    public function toggleActivo(ExportacionProducto $producto): RedirectResponse
    {
        $producto->update(['activo' => ! $producto->activo]);

        return back()->with('status', $producto->activo
            ? 'Producto reactivado: vuelve a estar disponible para listas nuevas.'
            : 'Producto archivado: ya no aparece al armar listas nuevas, pero conserva sus precios y su histórico.');
    }

    /**
     * Borrado FÍSICO, permitido solo cuando el producto no tiene nada colgando.
     *
     * Con asignaciones de precio la base los borraría en cascada y con items de
     * listas quedarían apuntando a NULL; en ambos casos se pierde información que no
     * se puede reconstruir. La salida correcta es archivar, y eso es lo que ofrece
     * el mensaje en vez de un «no se puede» a secas.
     */
    public function destroy(ExportacionProducto $producto): RedirectResponse
    {
        $precios = $producto->asignaciones()->count();
        $items = $producto->items()->count();

        if ($precios > 0 || $items > 0) {
            return redirect()
                ->route('productos.exportacion.show', $producto)
                ->with('error', $this->motivoNoBorrable($precios, $items));
        }

        $producto->delete();

        return redirect()
            ->route('productos.exportacion.index')
            ->with('status', 'Producto de exportación eliminado. No tenía precios de cliente ni aparecía en ninguna lista.');
    }

    private function motivoNoBorrable(int $precios, int $items): string
    {
        $partes = [];

        if ($precios > 0) {
            $partes[] = $precios === 1
                ? 'tiene 1 precio de cliente asignado'
                : "tiene {$precios} precios de cliente asignados";
        }

        if ($items > 0) {
            $partes[] = $items === 1
                ? 'aparece en 1 lista de empaque'
                : "aparece en {$items} listas de empaque";
        }

        return 'No se puede eliminar: este producto '.implode(' y ', $partes).'. '
            .'Borrarlo se llevaría esos precios negociados por delante y no se pueden reconstruir. '
            .'Archivalo con «Archivar producto»: deja de ofrecerse en listas nuevas y conserva todo su histórico.';
    }

    /** Formulario de importación del catálogo desde un Excel con el layout de la lista. */
    public function importarForm(): View
    {
        $archivoServidor = null;

        try {
            $archivoServidor = app(\App\Services\Exportaciones\ListaEmpaqueExcelService::class)->rutaPlantilla();
        } catch (\RuntimeException) {
            // Sin archivo guardado en el servidor: la vista lo indica y pide subir uno.
        }

        return view('productos.exportacion.importar', [
            'archivoServidor' => $archivoServidor,
            'totalProductos' => ExportacionProducto::count(),
        ]);
    }

    /** Importa desde el archivo subido o, si no se sube nada, desde el guardado en el servidor. */
    public function importar(Request $request, ImportadorCatalogoExportacion $importador): RedirectResponse
    {
        $request->validate([
            'archivo' => ['nullable', 'file', 'mimes:xlsx', 'max:10240'],
        ], [], ['archivo' => 'archivo Excel']);

        $ruta = $request->hasFile('archivo')
            ? $request->file('archivo')->getRealPath()
            : null; // null = usar el archivo guardado en el servidor

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
            ->route('productos.exportacion.index')
            ->with($resumen['errores'] === [] ? 'status' : 'error', $mensaje);
    }
}
