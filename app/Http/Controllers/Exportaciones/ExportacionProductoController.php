<?php

namespace App\Http\Controllers\Exportaciones;

use App\Enums\CategoriaProductoExportacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\PresentacionExportacionRequest;
use App\Http\Requests\Exportaciones\ProductoBaseExportacionRequest;
use App\Models\ExportacionProducto;
use App\Models\ExportacionProductoBase;
use App\Services\Exportaciones\CatalogoExportacion;
use App\Services\Exportaciones\ImportadorCatalogoExportacion;
use App\Services\Exportaciones\ListaEmpaqueExcelService;
use App\Services\Exportaciones\ListaPreciosExportacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Catálogo de productos de EXPORTACIÓN: listado agrupado, alta en un solo paso
 * (producto + primera presentación) y gestión de cada presentación. El producto
 * base se edita en {@see ExportacionProductoBaseController}.
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
    /**
     * Catálogo agrupado: categoría → producto → presentaciones. Son unos 50
     * productos, así que se muestran todos; la búsqueda y el estado filtran
     * presentaciones y un producto aparece si le queda alguna.
     */
    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->input('q', ''));
        $activo = (string) $request->input('activo', '1');
        $like = '%'.$busqueda.'%';

        $presentaciones = ExportacionProducto::query()
            ->with([
                'base',
                // Todas, no solo las activas: el editor de clientes precarga el último
                // precio de un cliente al que se le dejó de vender.
                'asignaciones' => fn ($q) => $q->orderBy('exportacion_cliente_id'),
                'asignaciones.cliente:id,nombre',
            ])
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre_es', 'like', $like)
                ->orWhere('nombre_en', 'like', $like)
                ->orWhere('codigo', 'like', $like)
                ->orWhere('unidad', 'like', $like)
                ->orWhereHas('base', fn ($b) => $b->where('nombre_es', 'like', $like)->orWhere('nombre_en', 'like', $like))))
            // Activa = la presentación Y su producto. Archivar el producto archiva todo.
            ->when($activo === '1', fn ($q) => $q->where('activo', true)
                ->where(fn ($w) => $w->whereNull('exportacion_producto_base_id')
                    ->orWhereHas('base', fn ($b) => $b->where('activo', true))))
            ->when($activo === '0', fn ($q) => $q->where(fn ($w) => $w->where('activo', false)
                ->orWhereHas('base', fn ($b) => $b->where('activo', false))))
            ->orderBy('unidades_por_caja')
            ->orderBy('gramos_por_unidad')
            ->get();

        $productos = $presentaciones->whereNotNull('exportacion_producto_base_id')
            ->groupBy('exportacion_producto_base_id')
            ->map(fn ($grupo) => ['base' => $grupo->first()->base, 'presentaciones' => $grupo->values()])
            ->sortBy(fn ($g) => Str::ascii(Str::lower($g['base']->nombre_es)))
            ->values();

        $secciones = collect(CategoriaProductoExportacion::cases())
            ->map(fn (CategoriaProductoExportacion $c) => [
                'titulo' => $c->label(),
                'productos' => $productos->filter(fn ($g) => $g['base']->categoria === $c)->values(),
            ])
            ->push(['titulo' => 'Sin categoría', 'productos' => $productos->filter(fn ($g) => $g['base']->categoria === null)->values()])
            ->filter(fn ($s) => $s['productos']->isNotEmpty())
            ->values();

        return view('productos.exportacion.index', [
            'secciones' => $secciones,
            'sinAgrupar' => $presentaciones->whereNull('exportacion_producto_base_id')->sortBy('nombre_es')->values(),
            'clientes' => app(ListaPreciosExportacion::class)->clientesActivos(),
            'filtros' => ['q' => $busqueda, 'activo' => $activo],
            'totales' => [
                'productos' => ExportacionProductoBase::where('activo', true)->count(),
                'activos' => ExportacionProducto::where('activo', true)->count(),
                'inactivos' => ExportacionProducto::where('activo', false)->count(),
            ],
        ]);
    }

    /** Alta en un solo paso: el producto y su primera presentación. */
    public function create(): View
    {
        return view('productos.exportacion.form', [
            'base' => new ExportacionProductoBase(['activo' => true]),
            'clientes' => app(ListaPreciosExportacion::class)->clientesActivos(),
        ]);
    }

    public function store(ProductoBaseExportacionRequest $request, CatalogoExportacion $catalogo, ListaPreciosExportacion $precios): RedirectResponse
    {
        $datos = $request->validated();

        // Producto, presentación y clientes van juntos: si falta un precio, no queda nada a medias.
        $base = DB::transaction(function () use ($catalogo, $precios, $datos) {
            $base = $catalogo->crearProducto($datos, $datos);
            $precios->sincronizarClientes($base->presentaciones()->sole(), $datos['clientes'] ?? []);

            return $base;
        });

        return redirect()
            ->to(route('productos.exportacion.index').'#producto-'.$base->id)
            ->with('status', "«{$base->nombre_es}» agregado al catálogo.");
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

    /** Editar una presentación: empaque, pesos y precio base. Los nombres son del producto. */
    public function edit(ExportacionProducto $producto): View
    {
        $producto->load('base');

        return view('productos.exportacion.presentacion', [
            'base' => $producto->base,
            'presentacion' => $producto,
        ]);
    }

    public function update(PresentacionExportacionRequest $request, ExportacionProducto $producto, CatalogoExportacion $catalogo): RedirectResponse
    {
        // Solo cambia el catálogo: los items ya agregados a listas conservan su snapshot.
        $catalogo->actualizarPresentacion($producto, $request->validated() + ['activo' => $request->boolean('activo', $producto->activo)]);

        return redirect()
            ->to(route('productos.exportacion.index').'#producto-'.$producto->exportacion_producto_base_id)
            ->with('status', "Presentación actualizada: {$producto->nombre_es}, {$producto->etiquetaEmpaque()}.");
    }

    /** A qué clientes se vende esta presentación y a qué precio, desde el catálogo. */
    public function clientes(Request $request, ExportacionProducto $producto, ListaPreciosExportacion $precios): RedirectResponse
    {
        $datos = $request->validate([
            'clientes' => ['nullable', 'array'],
            'clientes.*.activo' => ['nullable', 'boolean'],
            'clientes.*.precio' => ['nullable', 'numeric', 'min:0'],
        ], [], ['clientes.*.precio' => 'precio']);

        $cambios = $precios->sincronizarClientes($producto, $datos['clientes'] ?? []);

        return redirect()
            ->to(route('productos.exportacion.index').'#producto-'.$producto->exportacion_producto_base_id)
            ->with('status', $cambios === 0
                ? "Sin cambios en los clientes de {$producto->nombre_es}, {$producto->etiquetaEmpaque()}."
                : "Clientes actualizados: {$producto->nombre_es}, {$producto->etiquetaEmpaque()}.");
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
            .'Archivala con «Archivar presentación»: deja de ofrecerse en listas nuevas y conserva todo su histórico.';
    }

    /** Formulario de importación del catálogo desde un Excel con el layout de la lista. */
    public function importarForm(): View
    {
        $archivoServidor = null;

        try {
            $archivoServidor = app(ListaEmpaqueExcelService::class)->rutaPlantilla();
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
