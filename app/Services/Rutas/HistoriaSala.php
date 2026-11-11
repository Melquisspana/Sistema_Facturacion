<?php

namespace App\Services\Rutas;

use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\PedidoSala;
use App\Models\PedidoSalaLinea;
use App\Models\SalidaRutaEntrega;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;

/**
 * La historia de una sala, para quien le saca el pedido (maqueta del 10/11/2026): cuándo fue
 * su último pedido y de cuántas cajas, cuándo se le entregó por última vez y cómo pagó, cada
 * cuánto compra, cuándo toca volver y cuántas facturas tiene por cobrar. Solo lectura y sin
 * precios: el vendedor no ve precios al sacar pedido.
 *
 * Las fechas, el ritmo y «le toca» salen de {@see RitmoRutas::deSalas()}, la única fuente del
 * tablero; acá solo se le suma el detalle.
 */
class HistoriaSala
{
    public function __construct(private readonly RitmoRutas $ritmo) {}

    /**
     * @return array<string, mixed> la fila de {@see RitmoRutas::deSalas()} más `cajas` (?string,
     *                              las del último pedido), `pago` (?string, cómo pagó la última
     *                              entrega) y `porCobrar` (int, facturas)
     */
    public function de(ClienteSucursal $sala): array
    {
        $fila = $this->ritmo->deSalas($sala->newCollection([$sala]), CarbonImmutable::parse(HoraNegocio::fechaHoy()))[$sala->id];

        // Las cajas del último pedido de la sala, si es el de esa fecha (el ritmo también
        // mira los CCF de antes de que hubiera pedidos, que no tienen cantidades que mostrar).
        $pedido = PedidoSala::vigentes()->where('cliente_sucursal_id', $sala->id)->withSum('lineas', 'cantidad')
            ->orderByDesc('fecha')->orderByDesc('id')->first();
        $cajas = $pedido !== null && $fila['ultimoPedido'] !== null && $pedido->fecha->isSameDay($fila['ultimoPedido'])
            ? PedidoSalaLinea::legible((string) ($pedido->lineas_sum_cantidad ?? '0'))
            : null;

        // Cómo pagó la última entrega que alguien marcó, si es la de esa fecha (la del albarán
        // del supermercado no dice cómo pagó).
        $entrega = SalidaRutaEntrega::query()->entregadas()->sinFacturadosDespues()->where('cliente_sucursal_id', $sala->id)
            ->with('dte:id,cliente_id')->orderByDesc('fecha_resultado')->orderByDesc('id')->first();
        $pago = $entrega !== null && $fila['ultimaEntrega'] !== null && $entrega->fecha_resultado->isSameDay($fila['ultimaEntrega'])
            ? match (true) {
                $entrega->forma_cobro !== null => mb_strtolower($entrega->forma_cobro->label()),
                $entrega->dte !== null && DiaVendedor::esPortal($entrega->dte) => 'por el portal',
                default => 'quedan',
            }
        : null;

        // Por cobrar: lo que «Facturas por cobrar» tiene sin pago completo y los quedan que
        // dejó la tienda al entregar. Cada factura, una vez.
        $porCobrar = CobroDocumento::query()->pendienteDeCobro()->sinInvalidadosRetirables()
            ->whereHas('dte', fn ($q) => $q->where('cliente_sucursal_id', $sala->id))->pluck('dte_id')
            ->merge(SalidaRutaEntrega::query()->quedanPorCobrar()->where('cliente_sucursal_id', $sala->id)->pluck('dte_id'))
            ->filter()->unique()->count();

        return $fila + ['cajas' => $cajas, 'pago' => $pago, 'porCobrar' => $porCobrar];
    }
}
