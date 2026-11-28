<?php

namespace App\Services\Rutas;

use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PedidoSalaLinea;
use App\Models\SalidaRutaEntrega;
use App\Services\Cobros\BandejaCobros;
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
    public function __construct(private readonly RitmoRutas $ritmo, private readonly EntregasCcf $entregas) {}

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

        return $fila + ['cajas' => $cajas, 'pago' => $pago, 'porCobrar' => count($this->porCobrar([$sala->id])[$sala->id] ?? [])];
    }

    /**
     * Las facturas por cobrar de cada sala: los CCF que «Facturas por cobrar» tiene sin pago
     * completo y los quedan que dejó la tienda al entregar. Cada factura, una vez. Las mismas
     * consultas para una sala que para veinte (la usa también «Clientes» de Rutas).
     *
     * Con los filtros de la bandeja ({@see BandejaCobros::documentos()}): solo CCF, sin los
     * invalidados que ya no hay que mirar, sin los que sus NC dejan en saldo 0 y, en el
     * servidor real, sin los del ambiente de pruebas. Antes del 28/11/2026 contaba también
     * esos, y la sala salía «por cobrar» con una factura ya saldada.
     *
     * El quedan cuenta mientras su CCF no llegó a «Facturas por cobrar»: con el CCF ahí, decide
     * Cobros (lo de arriba). Y solo si el CCF cuenta en Rutas ({@see EntregasCcf::baseCcf()}):
     * un CCF invalidado después de entregado no se cobra. Queda afuera, sin Cobros, el que una
     * NC salda: no se vio en el uso y lo resuelve Cobros apenas lo da de alta.
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, list<int>> sala_id => dte_id
     */
    public function porCobrar(array $salaIds): array
    {
        if ($salaIds === []) {
            return [];
        }

        $cobros = CobroDocumento::query()->pendienteDeCobro()->sinInvalidadosRetirables()->where('tipo_dte', '03')
            ->when(config('dte.ambiente') === '01', fn ($q) => $q->whereDoesntHave('dte', fn ($d) => $d->where('ambiente', '00')))
            ->whereHas('dte', fn ($q) => $q->whereIn('cliente_sucursal_id', $salaIds));
        $deCobros = (clone $cobros)->whereKeyNot(CobroDocumento::idsSaldadosConNcEntre($cobros))->pluck('dte_id');
        $salaDe = Dte::whereKey($deCobros->all())->pluck('cliente_sucursal_id', 'id');
        $pares = $deCobros->map(fn ($dteId) => [$salaDe[$dteId] ?? null, $dteId])
            ->merge(SalidaRutaEntrega::query()->quedanPorCobrar()->whereIn('cliente_sucursal_id', $salaIds)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cobro_documentos')
                    ->whereColumn('cobro_documentos.dte_id', 'salida_ruta_entregas.dte_id'))
                ->whereIn('dte_id', $this->entregas->baseCcf()->select('dtes.id'))
                ->get(['id', 'cliente_sucursal_id', 'dte_id'])->map(fn (SalidaRutaEntrega $e) => [$e->cliente_sucursal_id, $e->dte_id]));

        $porSala = [];
        foreach ($pares as [$salaId, $dteId]) {
            if ($salaId !== null && $dteId !== null && ! in_array((int) $dteId, $porSala[$salaId] ?? [], true)) {
                $porSala[$salaId][] = (int) $dteId;
            }
        }

        return $porSala;
    }
}
