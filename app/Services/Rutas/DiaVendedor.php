<?php

namespace App\Services\Rutas;

use App\Enums\FormaCobroEntrega;
use App\Enums\ResultadoEntrega;
use App\Facades\Ajustes;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * El día del vendedor (maqueta del 10/11/2026): lo que entregó, lo que no se pudo y lo que
 * cobró, en cualquier viaje o salida (cualquier vendedor entrega cualquier CCF). Lo usan el
 * inicio, «Terminar el día» y su PDF. Solo lectura: no cierra nada ni mueve dinero; el
 * dinero se rinde con la liquidación de la oficina ({@see LiquidacionSalida}).
 *
 * Las sumas van en centavos ({@see CierreDia::centavos()}): ninguna depende de flotantes.
 */
class DiaVendedor
{
    /**
     * @return array{
     *     fecha: string,
     *     entregadas: Collection<int, SalidaRutaEntrega>,
     *     no_entregadas: Collection<int, SalidaRutaEntrega>,
     *     efectivo: int, cheque: int, transferencia: int, cobrado: int,
     *     cuantas: array<string, int>,
     *     quedan: array{cantidad: int, monto: int},
     *     portal: array{cantidad: int, monto: int},
     *     portal_id: ?int,
     *     pedidos: int,
     * }
     */
    public function resumen(PersonalRuta $persona, User $usuario, string $fecha): array
    {
        $filas = SalidaRutaEntrega::query()
            ->with(['dte:id,numero_control,total_pagar,cliente_id', 'sala:id,nombre'])
            ->sinFacturadosDespues()
            ->whereDate('fecha_resultado', $fecha)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('resultado', ResultadoEntrega::Entregado->value)->where('entregado_por_id', $persona->id))
                ->orWhere(fn (Builder $q) => $q->where('resultado', ResultadoEntrega::NoEntregado->value)->where('registrado_por', $usuario->id)))
            ->orderBy('fecha_resultado')->orderBy('id')
            ->get();

        $entregadas = $filas->where('resultado', ResultadoEntrega::Entregado)->values();
        $portalId = self::clientePortal();
        $resumen = [
            'fecha' => $fecha,
            'entregadas' => $entregadas,
            'no_entregadas' => $filas->where('resultado', ResultadoEntrega::NoEntregado)->values(),
            FormaCobroEntrega::Efectivo->value => 0,
            FormaCobroEntrega::Cheque->value => 0,
            FormaCobroEntrega::Transferencia->value => 0,
            'cuantas' => array_fill_keys(array_column(FormaCobroEntrega::cases(), 'value'), 0),
            'quedan' => ['cantidad' => 0, 'monto' => 0],
            'portal' => ['cantidad' => 0, 'monto' => 0],
            'portal_id' => $portalId,
        ];
        foreach ($entregadas as $entrega) {
            if ($entrega->forma_cobro !== null) {
                $resumen[$entrega->forma_cobro->value] += CierreDia::centavos($entrega->monto_cobrado ?? '0');
                $resumen['cuantas'][$entrega->forma_cobro->value]++;

                continue;
            }
            $tipo = $portalId !== null && (int) $entrega->dte?->cliente_id === $portalId ? 'portal' : 'quedan';
            $resumen[$tipo]['cantidad']++;
            $resumen[$tipo]['monto'] += CierreDia::centavos($entrega->dte?->total_pagar ?? '0');
        }
        $resumen['cobrado'] = $resumen['efectivo'] + $resumen['cheque'] + $resumen['transferencia'];
        $resumen['pedidos'] = PedidoSala::vigentes()->where('sacado_por_id', $persona->id)->whereDate('fecha', $fecha)->count();

        return $resumen;
    }

    /**
     * El supermercado que cobra por el portal: el cliente del pronto pago (Ajustes,
     * `ppq.cliente_default_id`). No se le cobra al entregar y su albarán confirma la entrega.
     */
    public static function clientePortal(): ?int
    {
        return Ajustes::cliente('ppq.cliente_default_id');
    }

    public static function esPortal(Dte $dte): bool
    {
        $portal = self::clientePortal();

        return $portal !== null && (int) $dte->cliente_id === $portal;
    }
}
