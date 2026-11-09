<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
use App\Services\Rutas\FacturarPedido;
use App\Support\Dte\ReglaOrdenCompra;
use App\Support\HoraNegocio;
use App\Support\OrdenCompra;
use App\Support\Sala;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Facturación › «Llegó de la fábrica»: una tarjeta por pedido (= por sala) con un envío
 * VIGENTE de la fábrica en el día elegido (hoy, si no se elige) o, con `sin_facturar=1`,
 * con algo por facturar de cualquier día. Lo que pidió el vendedor, lo que llegó, la «F» en
 * lo que faltó, la insignia de la OC, la hoja para imprimir y cómo va la facturación.
 *
 * Variables para la vista, además de las de siempre:
 *  - `sinFacturar` (bool): el filtro «Sin facturar» está puesto.
 *  - `facturacion[pedido_id]` ({@see FacturarPedido::estado()}): `por_facturar` (envíos;
 *    si hay, botón «Facturar»), `borrador` (?Dte: «Borrador abierto», al editor) y
 *    `facturados` (Dte: «Facturado en CCF n.º …», a la ficha). Sin consultas por tarjeta.
 *  - `otrosDiasSinFacturar` (int): pedidos con algo por facturar que no salen en el día
 *    elegido; si es mayor que 0, aviso arriba con enlace al filtro. 0 con el filtro puesto.
 *
 * La fábrica nunca factura: lo hace la oficina con la OC. Pide `dte.ver`: la fábrica y quien
 * no ve Facturación reciben 403. Facturar pide además `dte.gestionar` (FacturarPedidoController).
 */
class PorFacturarController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Dte::class);
        $filtro = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'sin_facturar' => ['nullable', 'boolean'],
        ], [
            'fecha.date_format' => 'Elegí un día válido.',
            'sin_facturar.boolean' => 'Elegí un filtro válido.',
        ]);
        $fecha = $filtro['fecha'] ?? HoraNegocio::fechaHoy();
        $sinFacturar = (bool) ($filtro['sin_facturar'] ?? false);

        $enviosDelDia = fn (Builder $e) => $e->whereNull('anulado_en')->whereDate('fecha', $fecha);
        $porFacturar = fn (Builder $e) => $e->porFacturar();

        $pedidos = PedidoSala::query()
            ->vigentes()
            // «Sin facturar»: lo que llegó cualquier día y todavía no tiene un CCF que cuente.
            ->whereHas('envios', $sinFacturar ? $porFacturar : $enviosDelDia)
            ->with([
                'sala:id,cliente_id,nombre,codigo,requiere_orden_compra', 'sala.cliente:id,requiere_orden_compra',
                'ruta:id,nombre', 'sacadoPor:id,nombre',
                'lineas.producto:id,codigo,nombre', 'lineas.envioLineasVigentes',
                // Todos los vigentes, para que cuadren con el total (que suma todos); con sus
                // líneas, para separar lo que llegó el día elegido de lo de otros días.
                'envios' => fn ($q) => $q->whereNull('anulado_en')->orderBy('numero'), 'envios.lineas',
                // El CCF de cada envío (también los borrados), para el estado de la tarjeta.
                'envios.dte',
            ])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        // Una sola consulta por página, no una por tarjeta. Las OC del correo ya vienen por
        // sala y sin CCF; la que anotó el vendedor se revisa acá: de otra sala o ya usada.
        $ocCorreo = OrdenCompraRecibida::pendientesPorSucursal();
        $conCcf = OrdenCompraRecibida::ccfPorNumero($pedidos->getCollection()
            ->map(fn (PedidoSala $p) => OrdenCompra::normalizar($p->numero_orden_compra))->all());
        $oc = [];
        $facturacion = [];
        foreach ($pedidos as $pedido) {
            $facturacion[$pedido->id] = FacturarPedido::estado($pedido);
            $delPedido = filled($pedido->numero_orden_compra);
            $deOtraSala = $delPedido ? ReglaOrdenCompra::salaDistinta($pedido->numero_orden_compra, $pedido->sala) : null;
            $ccfDeLaOc = $delPedido ? ($conCcf[OrdenCompra::normalizar($pedido->numero_orden_compra)] ?? null) : null;
            // El CCF de la OC es el de este mismo pedido y no queda nada por facturar: no es aviso.
            $esElPropio = $ccfDeLaOc !== null && $facturacion[$pedido->id]['por_facturar']->isEmpty()
                && $pedido->envios->contains('dte_id', $ccfDeLaOc->id);
            $aviso = match (true) {
                $ccfDeLaOc !== null && ! $esElPropio => 'Esa OC ya tiene un CCF.',
                $deOtraSala !== null => 'La OC es de la sala '.$deOtraSala.' y el pedido es de la '.Sala::normalizar($pedido->sala->codigo).'.',
                default => null,
            };
            $propuestas = $delPedido
                ? [$pedido->numero_orden_compra]
                : array_column($ocCorreo[$pedido->cliente_sucursal_id] ?? [], 'numero');
            $oc[$pedido->id] = [
                'propuestas' => $propuestas,
                'del_correo' => ! $delPedido,
                'aviso' => $aviso,
                'lista' => $aviso === null && ($propuestas !== [] || ! ReglaOrdenCompra::requerida($pedido->sala?->cliente, $pedido->sala)),
            ];
        }

        return view('facturacion.por-facturar.index', [
            'pedidos' => $pedidos,
            'oc' => $oc,
            'fecha' => $fecha,
            'esHoy' => $fecha === HoraNegocio::fechaHoy(),
            'sinFacturar' => $sinFacturar,
            'facturacion' => $facturacion,
            // Pedidos sin facturar que no salen en el día elegido: para el aviso de arriba.
            'otrosDiasSinFacturar' => $sinFacturar ? 0 : PedidoSala::query()->vigentes()
                ->whereHas('envios', $porFacturar)
                ->whereDoesntHave('envios', $enviosDelDia)
                ->count(),
        ]);
    }
}
