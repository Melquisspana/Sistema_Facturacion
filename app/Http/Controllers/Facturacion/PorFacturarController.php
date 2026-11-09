<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
use App\Support\Dte\ReglaOrdenCompra;
use App\Support\HoraNegocio;
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
                // Todos los vigentes, para que cuadren con «Llegó» (que suma todos).
                'envios' => fn ($q) => $q->whereNull('anulado_en')->orderBy('numero'),
            ])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        // Una sola consulta por página, no una por tarjeta.
        $ocCorreo = OrdenCompraRecibida::pendientesPorSucursal();
        $oc = [];
        foreach ($pedidos as $pedido) {
            $propuestas = filled($pedido->numero_orden_compra)
                ? [$pedido->numero_orden_compra]
                : array_column($ocCorreo[$pedido->cliente_sucursal_id] ?? [], 'numero');
            $oc[$pedido->id] = [
                'propuestas' => $propuestas,
                'del_correo' => ! filled($pedido->numero_orden_compra),
                'lista' => $propuestas !== [] || ! ReglaOrdenCompra::requerida($pedido->sala?->cliente, $pedido->sala),
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
