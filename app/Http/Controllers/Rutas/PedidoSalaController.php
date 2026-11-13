<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\CorregirPedidoOficinaRequest;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\Ruta;
use App\Services\Rutas\FacturarPedido;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosOficina;
use App\Services\Rutas\PedidosSala;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * «Pedidos» de la oficina: la lista con filtros por fecha, ruta y sala, y el detalle, donde
 * se agrega la OC y se ve lo que mandó la fábrica (sus envíos, vigentes y anulados). Desde
 * el 13/11/2026 la oficina también lo corrige o lo anula ({@see PedidosOficina}).
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
            ->with(['sala:id,nombre,codigo', 'ruta:id,nombre', 'salida:id,ruta_id', 'salida.ruta:id,nombre', 'sacadoPor:id,nombre'])
            ->withCount('lineas')
            ->when($filtros['desde'] ?? null, fn (Builder $q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $f) => $q->whereDate('fecha', '<=', $f))
            ->when($filtros['ruta_id'] ?? null, fn (Builder $q, $r) => $q->where('ruta_id', $r))
            ->when($filtros['salida'] ?? null, fn (Builder $q, $s) => $q->where('salida_ruta_id', $s))
            // Lo que llegó ese día: un envío VIGENTE de la fábrica con esa fecha.
            ->when($filtros['mandado_el'] ?? null, fn (Builder $q, $f) => $q->whereHas('envios', fn (Builder $e) => $e
                ->whereNull('anulado_en')->whereDate('fecha', $f)))
            // La sala por nombre o código; la tienda nueva, por el nombre que escribió el vendedor.
            ->when($filtros['sala'] ?? null, fn (Builder $q, $texto) => $q->where(fn (Builder $w) => $w
                ->whereHas('sala', fn (Builder $s) => $s
                    ->where('nombre', 'like', '%'.addcslashes($texto, '%_\\').'%')
                    ->orWhere('codigo', 'like', '%'.addcslashes($texto, '%_\\').'%'))
                ->orWhere('sala_nueva_nombre', 'like', '%'.addcslashes($texto, '%_\\').'%')))
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

    public function show(PedidoSala $pedido, FacturarPedido $facturar): View
    {
        Gate::authorize('view', $pedido);
        $pedido->load(['sala:id,nombre,codigo', 'ruta:id,nombre', 'salida', 'salida.ruta:id,nombre', 'sacadoPor:id,nombre', 'registradoPor:id,name', 'lineas.producto:id,codigo,nombre',
            'lineas.envioLineasVigentes', 'envios' => fn ($q) => $q->orderBy('numero'), 'envios.lineas.linea.producto:id,nombre',
            'envios.registradoPor:id,name', 'envios.anuladoPor:id,name', 'envios.dte']);

        // `regresa`: por producto_id, llegó − facturado en los CCF vigentes (FacturarPedido::regresa).
        return view('rutas.pedidos.show', ['pedido' => $pedido, 'regresa' => $facturar->regresa($pedido)]);
    }

    /**
     * La hoja de lo que salió: el sugerido en limpio para imprimir. Todo lo que pidió el
     * vendedor, lo que llegó en cada envío vigente y una «F» en lo que faltó. Sin precios.
     * `fecha` es el día que se miraba en «Llegó de la fábrica», para volver a ese día.
     */
    public function hoja(Request $request, PedidoSala $pedido, FacturarPedido $facturar): View
    {
        Gate::authorize('view', $pedido);
        $fecha = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'fecha.date_format' => 'Elegí un día válido.',
        ])['fecha'] ?? null;

        return view('rutas.pedidos.hoja', self::datosHoja($pedido, $facturar, $fecha));
    }

    /**
     * Lo que lleva la hoja. La usan la oficina (acá) y el vendedor desde «Por entregar».
     *
     * @return array{pedido: PedidoSala, regresa: array, volverAl: ?string}
     */
    public static function datosHoja(PedidoSala $pedido, FacturarPedido $facturar, ?string $fecha): array
    {
        $pedido->load(['sala:id,nombre,codigo', 'ruta:id,nombre', 'salida:id,ruta_id', 'salida.ruta:id,nombre', 'sacadoPor:id,nombre', 'lineas.producto:id,codigo,nombre',
            'lineas.envioLineasVigentes', 'envios' => fn ($q) => $q->whereNull('anulado_en')->orderBy('numero'), 'envios.lineas', 'envios.dte']);

        return [
            'pedido' => $pedido,
            'regresa' => $facturar->regresa($pedido),
            'volverAl' => $fecha ?? $pedido->envios->last()?->fecha?->format('Y-m-d'),
        ];
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

    /**
     * «Corregir»: la sala (con un buscador `?q=` que recarga, como en la tienda nueva) y las
     * cantidades de lo que falta mandar. Lo que se puede, lo dice {@see PedidosOficina}.
     */
    public function corregir(Request $request, PedidoSala $pedido, PedidosFabrica $fabrica): View
    {
        Gate::authorize('corregirEnOficina', $pedido);
        $texto = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $salas = mb_strlen($texto) >= 2 && ! $pedido->esTiendaNueva()
            ? ClienteSucursal::query()->buscarTexto($texto)->where('activo', true)
                ->whereKeyNot([...PedidosSala::salasSinPedidos(), $pedido->cliente_sucursal_id])
                ->with(['cliente:id,nombre', 'distrito:id,nombre'])->orderBy('nombre')->limit(20)->get()
            : collect();

        return view('rutas.pedidos.corregir', [
            'pedido' => $this->paraFormulario($pedido, $fabrica),
            'texto' => $texto,
            'salas' => $salas,
        ]);
    }

    public function guardarCorreccion(CorregirPedidoOficinaRequest $request, PedidoSala $pedido, PedidosOficina $oficina, PedidosSala $pedidos): RedirectResponse
    {
        $pedido = $oficina->corregir($pedido, $request->validated(), $request->user());
        $avisos = $pedidos->avisosCantidad($pedido);

        return redirect()->route('rutas.pedidos.show', $pedido)
            ->with('status', trim('Pedido corregido: «'.$pedido->nombreSala().'».'.($avisos ? ' '.implode(' ', $avisos) : '')));
    }

    /** «Anular»: el motivo y la confirmación. */
    public function confirmarAnular(PedidoSala $pedido, PedidosFabrica $fabrica): View
    {
        Gate::authorize('corregirEnOficina', $pedido);

        return view('rutas.pedidos.anular', ['pedido' => $this->paraFormulario($pedido, $fabrica)]);
    }

    public function anular(Request $request, PedidoSala $pedido, PedidosOficina $oficina): RedirectResponse
    {
        Gate::authorize('corregirEnOficina', $pedido);
        $datos = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'motivo' => ['required', 'string', 'max:500'],
        ], [
            'version.*' => 'Volvé a abrir el pedido y anulalo de nuevo.',
            'motivo.required' => 'Escribí por qué se anula.',
            'motivo.max' => 'El motivo puede tener hasta 500 caracteres.',
        ]);
        $oficina->anular($pedido, $datos['version'], $datos['motivo'], $request->user());

        return redirect()->route('rutas.pedidos.show', $pedido)->with('status', 'Pedido de «'.$pedido->nombreSala().'» anulado.');
    }

    /** Con lo que piden los formularios: las líneas con lo mandado, los envíos con su CCF y la versión. */
    private function paraFormulario(PedidoSala $pedido, PedidosFabrica $fabrica): PedidoSala
    {
        return $pedido->load($fabrica->relaciones())->load(['sala:id,nombre,codigo', 'lineas.producto:id,nombre,codigo', 'envios.dte']);
    }
}
