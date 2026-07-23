<?php

namespace App\Http\Controllers\Exportaciones;

use App\Exceptions\Exportaciones\FexYaExisteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\ExportacionRequest;
use App\Models\Exportacion;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use App\Services\Exportaciones\ListaEmpaqueExcelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exportaciones / Listas de Empaque. Módulo administrativo paralelo: genera el
 * Excel de lista de empaque desde la plantilla oficial. NO emite DTE, no toca
 * correlativos, firma, transmisión ni correo.
 */
class ExportacionController extends Controller
{
    public function index(Request $request): View
    {
        // Filtro Activas / Archivadas / Todas. Archivadas = pruebas/APITEST, no
        // exportaciones reales: ocultas por defecto del listado normal. NUNCA se
        // borran ni se filtran de show()/rutas por id: el acceso directo (o desde
        // el historial de una FEX) sigue funcionando siempre. `?archivadas=1` se
        // conserva como sinónimo de "todas" (enlaces/atajos previos a los chips).
        $filtro = (string) $request->input('filtro', $request->boolean('archivadas') ? 'todas' : 'activas');
        if (! in_array($filtro, ['activas', 'archivadas', 'todas'], true)) {
            $filtro = 'activas';
        }

        $exportaciones = Exportacion::query()
            ->withCount('items')
            ->with('cliente:id,cliente_id')
            ->when($filtro === 'activas', fn ($q) => $q->where('archivada', false))
            ->when($filtro === 'archivadas', fn ($q) => $q->where('archivada', true))
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('cliente_nombre', 'like', $buscar)
                    ->orWhere('factura', 'like', $buscar));
            })
            ->latest('fecha')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('exportaciones.index', ['exportaciones' => $exportaciones, 'filtro' => $filtro]);
    }

    public function create(): View
    {
        return view('exportaciones.create', [
            'productos' => $this->productosParaFormulario(),
            'clientes' => $this->clientesParaFormulario(),
            'defaults' => [
                'exportador_nombre' => config('exportaciones.exportador_nombre'),
                'exportador_direccion' => config('exportaciones.exportador_direccion'),
                'fda_reg_number' => config('exportaciones.fda_reg_number'),
            ],
        ]);
    }

    public function store(ExportacionRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        $avisos = [];

        $exportacion = DB::transaction(function () use ($datos, &$avisos) {
            $exportacion = Exportacion::create(collect($datos)->except('items')->all());
            $avisos = $this->crearItems($exportacion, $datos['items']);

            return $exportacion;
        });

        return redirect()
            ->route('exportaciones.show', $exportacion)
            ->with('status', 'Lista de empaque creada.')
            ->with('aviso_precios', $this->mensajeAvisoPrecios($avisos));
    }

    public function show(Exportacion $exportacion): View
    {
        $exportacion->load(['items.producto:id,nombre_es,activo', 'cliente.cliente', 'dte']);

        return view('exportaciones.show', ['exportacion' => $exportacion]);
    }

    public function edit(Exportacion $exportacion): View
    {
        $exportacion->load('items');

        return view('exportaciones.edit', [
            'exportacion' => $exportacion,
            'productos' => $this->productosParaFormulario(),
            'clientes' => $this->clientesParaFormulario(),
        ]);
    }

    public function update(ExportacionRequest $request, Exportacion $exportacion): RedirectResponse
    {
        $datos = $request->validated();
        $avisos = [];

        DB::transaction(function () use ($exportacion, $datos, &$avisos) {
            $exportacion->update(collect($datos)->except('items')->all());

            // Items existentes (traen id): solo se actualiza la cantidad y se CONSERVA el
            // snapshot. Items nuevos: snapshot del catálogo de hoy. Los no enviados se quitan.
            $enviados = collect($datos['items']);
            $idsConservar = $enviados->pluck('id')->filter()->map(fn ($id) => (int) $id);
            $exportacion->items()->whereNotIn('id', $idsConservar)->delete();

            foreach ($enviados as $item) {
                if (! empty($item['id'])) {
                    $exportacion->items()
                        ->whereKey((int) $item['id'])
                        ->update(['cantidad_cajas' => (int) $item['cantidad_cajas']]);
                }
            }

            $nuevos = $enviados->filter(fn ($item) => empty($item['id']))->values()->all();
            $avisos = $this->crearItems($exportacion, $nuevos);
        });

        return redirect()
            ->route('exportaciones.show', $exportacion)
            ->with('status', 'Lista de empaque actualizada.')
            ->with('aviso_precios', $this->mensajeAvisoPrecios($avisos));
    }

    /**
     * Bloquea la eliminación si la lista ya tiene una FEX vinculada (en cualquier
     * estado: borrador, generada, firmada, enviada, aceptada o rechazada). Nunca
     * borra el DTE al borrar la Lista; nunca se ejecuta silenciosamente.
     */
    public function destroy(Exportacion $exportacion): RedirectResponse
    {
        if ($exportacion->dte_id !== null) {
            return redirect()
                ->route('exportaciones.show', $exportacion)
                ->with('error', 'No se puede eliminar: esta lista ya tiene una Factura de exportación vinculada (DTE #'.$exportacion->dte_id.'). Elimina primero la vinculación si corresponde, o conserva ambos documentos.');
        }

        $exportacion->delete();

        return redirect()
            ->route('exportaciones.index')
            ->with('status', 'Lista de empaque eliminada.');
    }

    /**
     * Duplica la exportación COPIANDO los snapshots de los items tal cual (no
     * vuelve a leer el catálogo). Fecha de hoy y estado borrador.
     */
    public function duplicar(Exportacion $exportacion): RedirectResponse
    {
        $copia = DB::transaction(function () use ($exportacion) {
            $copia = $exportacion->replicate();
            $copia->fecha = now()->toDateString();
            $copia->estado = 'borrador';
            $copia->save();

            foreach ($exportacion->items as $item) {
                $nuevo = $item->replicate();
                $nuevo->exportacion_id = $copia->id;
                $nuevo->save();
            }

            return $copia;
        });

        return redirect()
            ->route('exportaciones.show', $copia)
            ->with('status', "Exportación duplicada desde la #{$exportacion->id}. Revisá fecha y factura.");
    }

    /** Marca la lista como APROBADA (revisada por la dueña). No emite nada. */
    public function aprobar(Exportacion $exportacion): RedirectResponse
    {
        $exportacion->update(['estado' => 'aprobada']);

        return redirect()
            ->route('exportaciones.show', $exportacion)
            ->with('status', 'Lista de empaque marcada como aprobada.');
    }

    /** Revierte la aprobación (vuelve a borrador). No emite nada. */
    public function desaprobar(Exportacion $exportacion): RedirectResponse
    {
        $exportacion->update(['estado' => 'borrador']);

        return redirect()
            ->route('exportaciones.show', $exportacion)
            ->with('status', 'Lista de empaque devuelta a borrador.');
    }

    /**
     * Archiva una Lista de PRUEBA (no real): la oculta del listado normal sin
     * borrarla ni tocar nada más. NO cambia `estado`, `dte_id`, items, precios
     * ni totales — si tiene una FEX vinculada, esa relación queda exactamente
     * igual (la FEX es evidencia real y no se toca). Sigue accesible por URL
     * directa o con el filtro "Mostrar archivadas".
     */
    public function archivar(Exportacion $exportacion): RedirectResponse
    {
        $exportacion->update(['archivada' => true, 'archivada_en' => now()]);

        return redirect()
            ->route('exportaciones.show', $exportacion)
            ->with('status', 'Lista de empaque archivada: ya no aparece en el listado normal. Seguís pudiendo verla por este enlace o con "Mostrar archivadas".');
    }

    /** Revierte el archivado (vuelve a aparecer en el listado normal). */
    public function desarchivar(Exportacion $exportacion): RedirectResponse
    {
        $exportacion->update(['archivada' => false, 'archivada_en' => null]);

        return redirect()
            ->route('exportaciones.show', $exportacion)
            ->with('status', 'Lista de empaque desarchivada.');
    }

    /**
     * Crea (o abre, si ya existe) la Factura de Exportación de esta Lista. Llama
     * ÚNICAMENTE al servicio orquestador: ninguna lógica de copia de líneas vive
     * acá. No genera JSON, no firma, no transmite, no consume correlativo por sí
     * misma (eso ocurre recién al "Generar" el borrador, un paso posterior y
     * manual del usuario).
     */
    public function crearFex(Exportacion $exportacion, Request $request, CrearFexDesdeExportacionService $service): RedirectResponse
    {
        try {
            $dte = $service->crear($exportacion, $request->user());
        } catch (FexYaExisteException $e) {
            return redirect()
                ->route('facturacion.edit', $e->dteId)
                ->with('status', 'Esta lista ya tiene una Factura de exportación creada.');
        } catch (ValidationException $e) {
            return redirect()
                ->route('exportaciones.show', $exportacion)
                ->with('error', 'No se pudo crear la factura de exportación: '.implode(' ', collect($e->errors())->flatten()->all()));
        }

        return redirect()
            ->route('facturacion.edit', $dte)
            ->with('status', 'Factura de exportación creada desde la Lista de Empaque.');
    }

    /** Descarga el Excel generado desde la plantilla oficial (solo hoja "Lista"). */
    public function excel(Exportacion $exportacion, ListaEmpaqueExcelService $service): BinaryFileResponse|RedirectResponse
    {
        if ($exportacion->items()->count() === 0) {
            return redirect()
                ->route('exportaciones.show', $exportacion)
                ->with('error', 'La exportación no tiene productos para generar el Excel.');
        }

        try {
            $ruta = $service->generar($exportacion);
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('exportaciones.show', $exportacion)
                ->with('error', $e->getMessage());
        }

        return response()->download($ruta, $service->nombreArchivo($exportacion))->deleteFileAfterSend();
    }

    /**
     * Crea items copiando el snapshot del catálogo (regla: la exportación no cambia
     * después). El precio usado sale PRIMERO de la lista del cliente
     * (exportacion_cliente_productos); si no hay, cae al precio base del catálogo
     * y se devuelve el aviso. Sin ningún precio, se rechaza con error de validación.
     *
     * @return list<string> nombres de productos que usaron precio base (fallback)
     */
    private function crearItems(Exportacion $exportacion, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $cliente = $exportacion->exportacion_cliente_id !== null
            ? \App\Models\ExportacionCliente::with('productos')->find($exportacion->exportacion_cliente_id)
            : null;

        // Un mismo producto repetido en el formulario se consolida sumando cajas.
        $porProducto = [];
        foreach ($items as $item) {
            $id = (int) $item['exportacion_producto_id'];
            $porProducto[$id] = ($porProducto[$id] ?? 0) + (int) $item['cantidad_cajas'];
        }

        $conPrecioBase = [];
        foreach ($porProducto as $productoId => $cantidad) {
            $producto = ExportacionProducto::findOrFail($productoId);

            $precio = $cliente?->precioPara($producto->id);
            if ($precio === null) {
                if ($producto->precio_caja === null) {
                    throw ValidationException::withMessages([
                        'items' => "«{$producto->nombre_es}» no tiene precio para este cliente ni precio base: asignale un precio antes de usarlo.",
                    ]);
                }
                $precio = (float) $producto->precio_caja;
                $conPrecioBase[] = $producto->nombre_es;
            }

            ExportacionItem::create([
                'exportacion_id' => $exportacion->id,
                'exportacion_producto_id' => $producto->id,
                'cantidad_cajas' => $cantidad,
                'precio_caja' => $precio,
            ] + $producto->datosSnapshot());
        }

        return $conPrecioBase;
    }

    private function mensajeAvisoPrecios(array $conPrecioBase): ?string
    {
        if ($conPrecioBase === []) {
            return null;
        }

        return 'Ojo: se usó el PRECIO BASE del catálogo (el cliente no tiene precio propio) para: '
            .implode(', ', $conPrecioBase).'.';
    }

    private function productosParaFormulario(): \Illuminate\Support\Collection
    {
        return ExportacionProducto::where('activo', true)
            ->orderBy('nombre_es')
            ->get(['id', 'nombre_es', 'nombre_en', 'unidad', 'unidades_por_caja', 'precio_caja', 'peso_neto_caja_kg', 'peso_bruto_caja_kg']);
    }

    private function clientesParaFormulario(): \Illuminate\Support\Collection
    {
        return \App\Models\ExportacionCliente::where('activo', true)
            ->with(['productos' => fn ($q) => $q->where('activo', true)])
            ->orderBy('nombre')
            ->get();
    }
}
