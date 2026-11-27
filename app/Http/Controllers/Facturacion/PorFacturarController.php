<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
use App\Policies\PedidoSalaPolicy;
use App\Services\Rutas\FacturarPedido;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosSala;
use App\Support\HoraNegocio;
use App\Support\OrdenCompra;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Facturación › «Llegó de pedidos» (Melqui, 24/11/2026): una fila por pedido (= por sala)
 * con su estado, la OC y «Facturar»; los productos se ven al tocar la fila. Abre con TODO lo
 * que falta facturar, sin importar el día, en grupos ({@see self::GRUPOS}): lo que se puede
 * facturar ya, lo que espera la OC, lo que espera el resto (salió una parte y ya se facturó:
 * sin «Facturar», para que el resto no salga en otro CCF con la misma OC sin que nadie lo
 * note) y lo facturado hoy, cerrado al final. Con `fecha`, lo que salió de pedidos ese día,
 * en los mismos grupos. Pastillas por zona (`ruta`, como en Rutas › «Por entregar»), solo
 * para mirar.
 *
 * Variables para la vista, además de las de siempre:
 *  - `pedidos` (Collection): los de la zona elegida, o todos.
 *  - `grupos`: [clave, titulo, pedidos] en el orden de la pantalla.
 *  - `zonas`: [clave, nombre, cuantos]; `ruta` (?string): la elegida; `filtroInvalido`.
 *  - `facturacion[pedido_id]` ({@see FacturarPedido::estado()}) y `oc[pedido_id]`
 *    ({@see FacturarPedido::oc()}, más `directo`: «Facturar» va directo al borrador,
 *    {@see FacturarPedido::vaDirecto()}). Sin consultas por fila.
 *  - `otrosDiasSinFacturar` (int): con `fecha`, los pedidos con algo por facturar que no
 *    salieron ese día; sin `fecha`, 0 (ya se ve todo).
 *
 * La fábrica nunca factura: lo hace la oficina con la OC. Pide `dte.ver`: la fábrica y quien
 * no ve Facturación reciben 403. Facturar pide además `dte.gestionar` (FacturarPedidoController),
 * igual que «Volvió a la fábrica» ({@see self::volvio()}).
 */
class PorFacturarController extends Controller
{
    /** Los grupos, en el orden de la pantalla. */
    private const GRUPOS = [
        'para_facturar' => 'Para facturar (OC lista)',
        'esperando_oc' => 'Esperando OC',
        'esperando_resto' => 'Esperando el resto',
        'facturados' => 'Ya facturados',
    ];

    private const SIN_ZONA = 'sin';

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Dte::class);
        $filtro = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'ruta' => ['nullable', 'string', 'max:20'],
        ], [
            'fecha.date_format' => 'Elegí un día válido.',
        ]);
        $fecha = $filtro['fecha'] ?? null;
        $hoy = HoraNegocio::fechaHoy();

        $enviosDelDia = fn (Builder $e) => $e->whereNull('anulado_en')->whereDate('fecha', $fecha);
        $porFacturar = fn (Builder $e) => $e->porFacturar();

        $todos = PedidoSala::query()
            ->where('origen', 'sala')
            ->vigentes()
            ->when(
                $fecha !== null,
                fn (Builder $q) => $q->whereHas('envios', $enviosDelDia),
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->whereHas('envios', $porFacturar)
                    // Con su borrador abierto (falta emitirlo) o con su CCF de hoy.
                    ->orWhereHas('envios', fn (Builder $e) => $e->whereNull('anulado_en')->whereHas('dte', fn (Builder $d) => $d->cuentaComoFacturado()
                        ->where(fn (Builder $d) => $d->where('estado', EstadoDte::Borrador->value)->orWhereDate('fecha_emision', $hoy))))
                    // Ya salió una parte y espera el resto.
                    ->orWhere(fn (Builder $p) => $p->whereIn('estado', [EstadoPedidoSala::Parcial->value, EstadoPedidoSala::Listo->value])
                        ->whereHas('envios', fn (Builder $e) => $e->whereNull('anulado_en')))),
            )
            ->with([
                'sala:id,cliente_id,nombre,codigo,requiere_orden_compra', 'sala.cliente:id,requiere_orden_compra',
                'ruta:id,nombre', 'salida:id,ruta_id', 'salida.ruta:id,nombre', 'sacadoPor:id,nombre',
                'lineas.producto:id,codigo,nombre', 'lineas.envioLineasVigentes',
                // Todos los vigentes, para que cuadren con el total (que suma todos); con sus
                // líneas, para separar lo que llegó el día elegido de lo de otros días.
                'envios' => fn ($q) => $q->whereNull('anulado_en')->orderBy('numero'), 'envios.lineas',
                // El CCF de cada envío (también los borrados), para el estado de la fila.
                'envios.dte',
            ])
            ->get();

        // Una sola consulta para todos, no una por fila. Las OC del correo ya vienen por sala
        // y sin CCF; la que anotó el vendedor se revisa en FacturarPedido::oc().
        $ocCorreo = OrdenCompraRecibida::pendientesPorSucursal();
        // Con otro pedido de la sala sin OC, la del correo puede ser de ese: se revisa.
        $sinOc = FacturarPedido::sinOcPorSala();
        $conCcf = OrdenCompraRecibida::ccfPorNumero($todos
            ->map(fn (PedidoSala $p) => OrdenCompra::normalizar($p->numero_orden_compra))->all());
        $emisorSolo = FacturarPedido::emisorSolo();
        $oc = [];
        $facturacion = [];
        $grupoDe = [];
        foreach ($todos as $pedido) {
            $estado = $facturacion[$pedido->id] = FacturarPedido::estado($pedido);
            $oc[$pedido->id] = FacturarPedido::oc($pedido, $estado, $ocCorreo[$pedido->cliente_sucursal_id] ?? [],
                $conCcf[OrdenCompra::normalizar($pedido->numero_orden_compra)] ?? null, FacturarPedido::otroSinOc($pedido, $sinOc));
            // Una sola OC clara: «Facturar» va directo al borrador (24/11/2026).
            $oc[$pedido->id]['directo'] = FacturarPedido::vaDirecto($pedido, $estado, $oc[$pedido->id], $emisorSolo);
            $grupoDe[$pedido->id] = match (true) {
                $estado['por_facturar']->isNotEmpty() || $estado['borrador'] !== null => $oc[$pedido->id]['lista'] ? 'para_facturar' : 'esperando_oc',
                $pedido->enParte() => 'esperando_resto',
                default => 'facturados',
            };
        }

        // Por zona: la del pedido (la tienda nueva, la de su viaje), como dice cada fila.
        $zonaDe = fn (PedidoSala $p) => (string) ($p->ruta_id ?? ($p->esTiendaNueva() ? $p->salida?->ruta_id : null) ?? self::SIN_ZONA);
        $zonas = $todos->groupBy($zonaDe)
            ->map(fn ($lista, $clave) => ['clave' => (string) $clave, 'nombre' => $lista->first()->zonaNombre(), 'cuantos' => $lista->count()])
            ->sortBy(fn (array $z) => [$z['clave'] === self::SIN_ZONA ? 1 : 0, mb_strtolower($z['nombre'])])
            ->values();
        // La zona es solo para mirar (?ruta=): no guarda nada.
        $ruta = (string) ($filtro['ruta'] ?? '');
        $ruta = $zonas->contains('clave', $ruta) ? $ruta : null;
        $pedidos = $todos
            ->filter(fn (PedidoSala $p) => $ruta === null || $zonaDe($p) === $ruta)
            ->sortBy(fn (PedidoSala $p) => [$zonaDe($p) === self::SIN_ZONA ? 1 : 0, mb_strtolower($p->zonaNombre()), $p->fecha->format('Y-m-d'), $p->id])
            ->values();

        return view('facturacion.por-facturar.index', [
            'pedidos' => $pedidos,
            'grupos' => collect(self::GRUPOS)->map(fn (string $titulo, string $clave) => [
                'clave' => $clave,
                'titulo' => $clave === 'facturados' && $fecha === null ? 'Ya facturados hoy' : $titulo,
                'pedidos' => $pedidos->filter(fn (PedidoSala $p) => $grupoDe[$p->id] === $clave)->values(),
            ])->values(),
            'zonas' => $zonas,
            'ruta' => $ruta,
            'filtroInvalido' => filled($filtro['ruta'] ?? null) && $ruta === null,
            'oc' => $oc,
            'fecha' => $fecha,
            'facturacion' => $facturacion,
            // Cuántos pedidos más hay de cada tienda nueva con el mismo nombre (13/11/2026).
            'mismaTienda' => PedidosSala::cuantosMasDeLaTienda($pedidos),
            // Con un día elegido: los pedidos sin facturar que no salieron ese día.
            'otrosDiasSinFacturar' => $fecha === null ? 0 : PedidoSala::query()->vigentes()
                ->where('origen', 'sala')
                ->whereHas('envios', $porFacturar)
                ->whereDoesntHave('envios', $enviosDelDia)
                ->count(),
        ]);
    }

    /**
     * «Volvió a la fábrica» (Melqui, 27/11/2026): lo que salió no se entregó y volvió. El
     * pedido queda cerrado y sale de esta lista. Lo puede quien factura desde acá
     * ({@see PedidoSalaPolicy::volverALaFabrica()}); la regla vive en
     * {@see PedidosFabrica::volvioALaFabrica()}. `volvio_pedido` solo sirve para reabrir el
     * diálogo de esa fila si el motivo no pasa.
     */
    public function volvio(Request $request, PedidoSala $pedido, PedidosFabrica $fabrica): RedirectResponse
    {
        Gate::authorize('volverALaFabrica', $pedido);
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:200'],
        ], [
            'motivo.required' => PedidosFabrica::VOLVIO_SIN_MOTIVO,
            'motivo.max' => PedidosFabrica::MOTIVO_LARGO,
        ]);

        $cambio = $fabrica->volvioALaFabrica($pedido, $datos['motivo'], $request->user());

        return back()->with('status', $cambio
            ? 'El pedido de «'.$pedido->nombreSala().'» quedó cerrado: volvió a la fábrica.'
            : 'Ese pedido ya estaba marcado: volvió a la fábrica.');
    }
}
