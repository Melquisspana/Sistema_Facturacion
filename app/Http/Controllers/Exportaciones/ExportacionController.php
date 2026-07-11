<?php

namespace App\Http\Controllers\Exportaciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\ExportacionRequest;
use App\Models\Exportacion;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Services\Exportaciones\FacturaExportacionExcel;
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
        $exportaciones = Exportacion::query()
            ->withCount('items')
            ->when($request->filled('q'), function ($q) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('cliente_nombre', 'like', $buscar)
                    ->orWhere('factura', 'like', $buscar));
            })
            ->latest('fecha')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('exportaciones.index', ['exportaciones' => $exportaciones]);
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
        $exportacion->load('items.producto:id,nombre_es,activo');

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

    public function destroy(Exportacion $exportacion): RedirectResponse
    {
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
     * Vista de AYUDA para preparar la factura de exportación: arma las líneas
     * (descripción es/en - units, cantidad, precio unitario, total) desde el
     * snapshot de la lista. SOLO LECTURA: no es un DTE, no emite, no transmite,
     * no toca correlativos, no persiste nada ni toca Conta Portable.
     */
    public function prepararFactura(Exportacion $exportacion): View|RedirectResponse
    {
        $exportacion->load('items');

        if ($exportacion->items->isEmpty()) {
            return redirect()
                ->route('exportaciones.show', $exportacion)
                ->with('error', 'La lista no tiene productos para preparar la factura.');
        }

        return view('exportaciones.preparar-factura', ['exportacion' => $exportacion]);
    }

    /** Descarga el Excel SIMPLE (4 columnas) para copiar los datos a mano. No emite nada. */
    public function excelFactura(Exportacion $exportacion, FacturaExportacionExcel $service): BinaryFileResponse|RedirectResponse
    {
        $exportacion->load('items');

        if ($exportacion->items->isEmpty()) {
            return redirect()
                ->route('exportaciones.show', $exportacion)
                ->with('error', 'La lista no tiene productos para generar el Excel.');
        }

        return response()->download($service->generar($exportacion), $service->nombreArchivo($exportacion))->deleteFileAfterSend();
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
