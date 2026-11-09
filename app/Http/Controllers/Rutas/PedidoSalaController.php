<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\PedidoSala;
use App\Models\Ruta;
use App\Services\Rutas\PedidosSala;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * «Pedidos» de la oficina: la lista con filtros por fecha, ruta y sala, y el detalle, donde
 * se agrega la OC y se ve lo que mandó la fábrica (sus envíos, vigentes y anulados).
 */
class PedidoSalaController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'ruta_id' => ['nullable', 'integer'],
            'sala' => ['nullable', 'string', 'max:100'],
            'salida' => ['nullable', 'integer'],
            'mandado_el' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $pedidos = PedidoSala::query()
            ->with(['sala:id,nombre,codigo', 'ruta:id,nombre', 'sacadoPor:id,nombre'])
            ->withCount('lineas')
            ->when($filtros['desde'] ?? null, fn (Builder $q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $f) => $q->whereDate('fecha', '<=', $f))
            ->when($filtros['ruta_id'] ?? null, fn (Builder $q, $r) => $q->where('ruta_id', $r))
            ->when($filtros['salida'] ?? null, fn (Builder $q, $s) => $q->where('salida_ruta_id', $s))
            // Lo que llegó ese día: un envío VIGENTE de la fábrica con esa fecha.
            ->when($filtros['mandado_el'] ?? null, fn (Builder $q, $f) => $q->whereHas('envios', fn (Builder $e) => $e
                ->whereNull('anulado_en')->whereDate('fecha', $f)))
            ->when($filtros['sala'] ?? null, fn (Builder $q, $texto) => $q->whereHas('sala', fn (Builder $s) => $s
                ->where('nombre', 'like', '%'.addcslashes($texto, '%_\\').'%')
                ->orWhere('codigo', 'like', '%'.addcslashes($texto, '%_\\').'%')))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('rutas.pedidos.index', [
            'pedidos' => $pedidos,
            'rutas' => Ruta::orderBy('nombre')->get(['id', 'nombre']),
            'filtrado' => array_filter($filtros, fn ($v) => $v !== null && $v !== '') !== [],
        ]);
    }

    public function show(PedidoSala $pedido): View
    {
        Gate::authorize('view', $pedido);
        $pedido->load(['sala:id,nombre,codigo', 'ruta:id,nombre', 'salida', 'sacadoPor:id,nombre', 'registradoPor:id,name', 'lineas.producto:id,codigo,nombre',
            'lineas.envioLineasVigentes', 'envios' => fn ($q) => $q->orderBy('numero'), 'envios.lineas.linea.producto:id,nombre',
            'envios.registradoPor:id,name', 'envios.anuladoPor:id,name']);

        return view('rutas.pedidos.show', ['pedido' => $pedido]);
    }

    /**
     * La hoja de lo que salió: el sugerido en limpio para imprimir. Todo lo que pidió el
     * vendedor, lo que llegó en cada envío vigente y una «F» en lo que faltó. Sin precios.
     */
    public function hoja(PedidoSala $pedido): View
    {
        Gate::authorize('view', $pedido);
        $pedido->load(['sala:id,nombre,codigo', 'ruta:id,nombre', 'sacadoPor:id,nombre', 'lineas.producto:id,codigo,nombre',
            'lineas.envioLineasVigentes', 'envios' => fn ($q) => $q->whereNull('anulado_en')->orderBy('numero'), 'envios.lineas']);

        return view('rutas.pedidos.hoja', ['pedido' => $pedido]);
    }

    /** Agregar o corregir la OC, que suele llegar después por EDI. Avisa si su forma no cuadra. */
    public function ordenCompra(Request $request, PedidoSala $pedido, PedidosSala $pedidos): RedirectResponse
    {
        $datos = $request->validate(['numero_orden_compra' => ['nullable', 'string', 'max:30']], [
            'numero_orden_compra.max' => 'El número de orden de compra puede tener hasta 30 caracteres.',
        ]);
        $pedido = $pedidos->cambiarOrdenCompra($pedido, $datos['numero_orden_compra'] ?? null, $request->user());
        $avisos = $pedido->avisosOrdenCompra();

        return redirect()->route('rutas.pedidos.show', $pedido)->with('status', trim(
            ($pedido->numero_orden_compra ? 'Orden de compra guardada.' : 'Orden de compra quitada.')
            .($avisos ? ' Revisala: '.implode(' ', $avisos) : '')
        ));
    }
}
