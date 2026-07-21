<?php

namespace App\Http\Controllers\Exportaciones;

use App\Enums\TipoCliente;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exportaciones\ExportacionClienteRequest;
use App\Models\Cliente;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->with('cliente:id,nombre,direccion,num_documento')
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

    /** Detalle del cliente: gestión de su lista de productos/precios/presentaciones. */
    public function show(Request $request, ExportacionCliente $cliente): View
    {
        // Filtro "solo habilitados": ver únicamente lo que el cliente tiene activo.
        $soloHabilitados = $request->boolean('habilitados');
        $cliente->load([
            'cliente',
            'productos' => fn ($q) => $q->when($soloHabilitados, fn ($w) => $w->where('activo', true)),
            'productos.producto:id,nombre_es,nombre_en,unidad,unidades_por_caja,gramos_por_unidad,onzas_por_unidad,precio_caja,activo',
        ]);

        $asignados = $cliente->productos->pluck('exportacion_producto_id');
        $disponibles = ExportacionProducto::where('activo', true)
            ->whereNotIn('id', $asignados)
            ->orderBy('nombre_es')
            ->get(['id', 'nombre_es', 'unidades_por_caja', 'precio_caja']);

        // Posibles orígenes para "copiar precios desde otro cliente".
        // Nota: el filtro de productos_count > 0 se hace en PHP (no con HAVING):
        // HAVING sobre un alias de withCount() sin GROUP BY es válido en MySQL pero
        // rompe en SQLite (motor de los tests) — la lista es chica, filtrar acá no
        // tiene costo real.
        $otrosClientes = ExportacionCliente::where('id', '!=', $cliente->id)
            ->withCount(['productos as productos_count' => fn ($q) => $q->where('activo', true)])
            ->orderBy('nombre')
            ->get()
            ->filter(fn ($c) => $c->productos_count > 0)
            ->values();

        return view('exportaciones.clientes.show', [
            'cliente' => $cliente,
            'disponibles' => $disponibles,
            'otrosClientes' => $otrosClientes,
            'soloHabilitados' => $soloHabilitados,
            'clientesDte' => Cliente::where('tipo_cliente', TipoCliente::Exportacion)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'num_documento']),
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

    /**
     * Vincula (o cambia el vínculo) con un Cliente DTE real existente. NO crea
     * clientes DTE nuevos: solo guarda la relación con uno ya existente y de tipo
     * exportación (el que exige el receptor de una FEX).
     */
    public function vincularClienteDte(Request $request, ExportacionCliente $cliente): RedirectResponse
    {
        $datos = $request->validate([
            'cliente_id' => [
                'required',
                'integer',
                Rule::exists('clientes', 'id')->where('tipo_cliente', TipoCliente::Exportacion->value),
            ],
        ], [
            'cliente_id.exists' => 'El cliente DTE elegido no existe o no es de tipo exportación.',
        ], [
            'cliente_id' => 'cliente DTE',
        ]);

        $cliente->update(['cliente_id' => $datos['cliente_id']]);

        return redirect()
            ->route('exportaciones.clientes.show', $cliente)
            ->with('status', 'Cliente DTE vinculado correctamente.');
    }

    /**
     * Quita el vínculo con el Cliente DTE. Bloqueado si existe alguna Lista de
     * este cliente administrativo con una FEX ya asociada (dte_id), para no dejar
     * una FEX real huérfana de su receptor administrativo sin que nadie lo note.
     */
    public function desvincularClienteDte(ExportacionCliente $cliente): RedirectResponse
    {
        $tieneListasConFex = $cliente->exportaciones()->whereNotNull('dte_id')->exists();

        if ($tieneListasConFex) {
            throw ValidationException::withMessages([
                'cliente_id' => 'No se puede quitar el vínculo: hay listas de este cliente con una Factura de exportación ya asociada.',
            ]);
        }

        $cliente->update(['cliente_id' => null]);

        return redirect()
            ->route('exportaciones.clientes.show', $cliente)
            ->with('status', 'Vínculo con el Cliente DTE eliminado.');
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

        $this->validarPrecioCero($request, (float) $datos['precio_caja']);

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

        $this->validarPrecioCero($request, (float) $datos['precio_caja']);

        // Solo cambia la lista de precios: exportaciones ya creadas conservan su snapshot.
        $asignacion->update($datos);

        return back()->with('status', 'Precio actualizado.');
    }

    /**
     * Precio $0.00 solo con confirmación explícita (evita ceros por dedazo).
     * Negativos ya los bloquea la regla min:0.
     */
    private function validarPrecioCero(Request $request, float $precio): void
    {
        if ($precio == 0.0 && ! $request->boolean('confirmar_cero')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'precio_caja' => 'El precio quedó en $0.00: confirmá que es intencional para guardarlo.',
            ]);
        }
    }

    public function destroyProducto(ExportacionCliente $cliente, ExportacionClienteProducto $asignacion): RedirectResponse
    {
        abort_unless($asignacion->exportacion_cliente_id === $cliente->id, 404);
        $asignacion->delete();

        return back()->with('status', 'Producto quitado de la lista del cliente.');
    }

    /**
     * Asigna de un golpe todo el catálogo activo que falte, usando el precio base.
     * Productos sin precio base (o con base $0) quedan FUERA: nunca se crean
     * precios en cero silenciosamente; se asignan a mano confirmando el precio.
     */
    public function asignarCatalogo(ExportacionCliente $cliente): RedirectResponse
    {
        $asignados = $cliente->productos()->pluck('exportacion_producto_id');
        $faltantes = ExportacionProducto::where('activo', true)
            ->whereNotIn('id', $asignados)
            ->get(['id', 'precio_caja']);

        $sinPrecioBase = 0;
        foreach ($faltantes as $producto) {
            if ($producto->precio_caja === null || (float) $producto->precio_caja <= 0) {
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
            $mensaje .= " {$sinPrecioBase} sin precio base (o con base $0) quedaron fuera: asignalos manualmente con su precio.";
        }

        return back()->with('status', $mensaje);
    }

    /**
     * Copia los productos/precios ACTIVOS de otro cliente a este. Por defecto NO
     * sobrescribe los que ya existen en el destino; con modo "sobrescribir"
     * actualiza el precio de los existentes. Nunca toca snapshots de
     * exportaciones ya creadas (solo cambia la lista de precios).
     */
    public function copiarPrecios(Request $request, ExportacionCliente $cliente): RedirectResponse
    {
        $datos = $request->validate([
            'origen_id' => [
                'required',
                'integer',
                Rule::exists('exportacion_clientes', 'id'),
                Rule::notIn([$cliente->id]),
            ],
            'modo' => ['required', Rule::in(['conservar', 'sobrescribir'])],
        ], [
            'origen_id.not_in' => 'El cliente origen debe ser distinto al destino.',
        ], [
            'origen_id' => 'cliente origen',
            'modo' => 'modo de copia',
        ]);

        $origen = ExportacionCliente::findOrFail($datos['origen_id']);
        $existentes = $cliente->productos()->get()->keyBy('exportacion_producto_id');

        $copiados = 0;
        $sobrescritos = 0;
        $omitidos = 0;
        foreach ($origen->productos()->where('activo', true)->get() as $asignacion) {
            $existente = $existentes->get($asignacion->exportacion_producto_id);
            if ($existente === null) {
                $cliente->productos()->create([
                    'exportacion_producto_id' => $asignacion->exportacion_producto_id,
                    'precio_caja' => $asignacion->precio_caja,
                    'activo' => true,
                ]);
                $copiados++;
            } elseif ($datos['modo'] === 'sobrescribir') {
                $existente->update(['precio_caja' => $asignacion->precio_caja]);
                $sobrescritos++;
            } else {
                $omitidos++;
            }
        }

        $mensaje = "Precios copiados desde «{$origen->nombre}»: {$copiados} nuevos";
        $mensaje .= $datos['modo'] === 'sobrescribir'
            ? ", {$sobrescritos} sobrescritos."
            : ", {$omitidos} ya existían y se conservaron.";
        $mensaje .= ' Las exportaciones ya creadas no cambian (snapshot).';

        return redirect()
            ->route('exportaciones.clientes.show', $cliente)
            ->with('status', $mensaje);
    }
}
