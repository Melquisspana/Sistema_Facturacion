<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
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
 * VIGENTE de la fábrica en el día elegido (hoy, si no se elige). Lo que pidió el vendedor,
 * lo que llegó, la «F» en lo que faltó, la insignia de la OC y la hoja para imprimir.
 *
 * Solo lectura: todavía no factura (la fábrica nunca factura; lo hace la oficina con la OC).
 * Pide `dte.ver`: la fábrica y quien no ve Facturación reciben 403.
 */
class PorFacturarController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Dte::class);
        $fecha = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'fecha.date_format' => 'Elegí un día válido.',
        ])['fecha'] ?? HoraNegocio::fechaHoy();

        $enviosDelDia = fn (Builder $e) => $e->whereNull('anulado_en')->whereDate('fecha', $fecha);

        $pedidos = PedidoSala::query()
            ->vigentes()
            ->whereHas('envios', $enviosDelDia)
            ->with([
                'sala:id,cliente_id,nombre,codigo,requiere_orden_compra', 'sala.cliente:id,requiere_orden_compra',
                'ruta:id,nombre', 'sacadoPor:id,nombre',
                'lineas.producto:id,codigo,nombre', 'lineas.envioLineasVigentes',
                // Todos los vigentes, para que cuadren con el total (que suma todos); con sus
                // líneas, para separar lo que llegó el día elegido de lo de otros días.
                'envios' => fn ($q) => $q->whereNull('anulado_en')->orderBy('numero'), 'envios.lineas',
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
        foreach ($pedidos as $pedido) {
            $delPedido = filled($pedido->numero_orden_compra);
            $deOtraSala = $delPedido ? ReglaOrdenCompra::salaDistinta($pedido->numero_orden_compra, $pedido->sala) : null;
            $aviso = match (true) {
                $delPedido && isset($conCcf[OrdenCompra::normalizar($pedido->numero_orden_compra)]) => 'Esa OC ya tiene un CCF.',
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
        ]);
    }
}
