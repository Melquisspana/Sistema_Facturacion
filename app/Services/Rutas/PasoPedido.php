<?php

namespace App\Services\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\SalidaRutaEntrega;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * En qué paso va un pedido de sala, y cuándo un CCF cuenta como entregado. Es la ÚNICA regla:
 * la usan la pestaña «Viajes» ({@see TableroViajes}) y el cierre del viaje
 * ({@see Viajes::todoEntregado()}), para que no digan cosas distintas.
 *
 *   1. Sacado: registrado y la fábrica todavía no lo tocó.
 *   2. En fábrica: en armado, mandado en parte, o mandado y todavía sin un CCF que cuente
 *      (nunca facturado, o su CCF se invalidó, rechazó o borró).
 *   3. Facturado: todo lo mandado está en CCF que cuentan (`pedido_sala_envios.dte_id`).
 *   4. Entregado: cada uno de esos CCF está entregado ({@see entregados()}).
 *
 * Un pedido con partes en pasos distintos va en el de la parte más atrasada. Lo que la
 * fábrica cerró sin mandar («No se trae») va aparte y no espera nada.
 *
 * Un CCF está entregado con su entrega registrada, en cualquier salida y por quien sea, o
 * con un albarán de entrega inequívoco del supermercado ({@see AlbaranLocalizador}). Un «No
 * se pudo» o un albarán de avería no lo dan por entregado.
 */
class PasoPedido
{
    public const SACADO = 'sacado';

    public const EN_FABRICA = 'en_fabrica';

    public const FACTURADO = 'facturado';

    public const ENTREGADO = 'entregado';

    public const NO_SE_TRAE = 'no_se_trae';

    /** Los cuatro pasos, en orden. */
    public const PASOS = [self::SACADO, self::EN_FABRICA, self::FACTURADO, self::ENTREGADO];

    public function __construct(private readonly AlbaranLocalizador $albaranes) {}

    /**
     * El paso del pedido, su detalle y desde cuándo está ahí. El pedido trae sus envíos
     * vigentes con su `dte` cargados.
     *
     * @param  array<int, string>  $entregados  de {@see entregados()}
     * @return array{0: string, 1: ?string, 2: CarbonImmutable}
     */
    public function de(PedidoSala $pedido, array $entregados): array
    {
        $sacado = CarbonImmutable::parse($pedido->fecha->toDateString());

        return match (true) {
            $pedido->estado === EstadoPedidoSala::NoSeTrae => [self::NO_SE_TRAE, 'La fábrica no lo trae', $sacado],
            $pedido->estaRegistrado() && $pedido->armando_desde === null => [self::SACADO, null, $sacado],
            $pedido->estaPorMandar() => [self::EN_FABRICA, $pedido->estaRegistrado() ? 'En armado' : 'Mandado en parte', $sacado],
            default => $this->despuesDeLaFabrica($pedido, $entregados, $sacado),
        };
    }

    /** ¿El pedido ya no espera nada? Entregado, o la fábrica no lo trae. */
    public static function terminado(string $paso): bool
    {
        return in_array($paso, [self::ENTREGADO, self::NO_SE_TRAE], true);
    }

    /**
     * Mandado: en fábrica mientras algo no esté en un CCF que cuente; si no, facturado
     * desde el CCF sin entregar más viejo, o entregado desde la última entrega.
     *
     * @param  array<int, string>  $entregados
     * @return array{0: string, 1: ?string, 2: CarbonImmutable}
     */
    private function despuesDeLaFabrica(PedidoSala $pedido, array $entregados, CarbonImmutable $sacado): array
    {
        $envios = $pedido->envios;
        if ($envios->isEmpty() || $envios->contains(fn (PedidoSalaEnvio $e) => ! $e->estaFacturado())) {
            return [self::EN_FABRICA, 'Llegó de la fábrica; falta facturar', $sacado];
        }

        $dtes = $envios->pluck('dte')->unique('id');
        $sinEntregar = $dtes->reject(fn (Dte $d) => isset($entregados[$d->id]));
        if ($sinEntregar->isNotEmpty()) {
            return [self::FACTURADO, null, CarbonImmutable::parse($sinEntregar->min(fn (Dte $d) => $d->fecha_emision->toDateString()))];
        }

        return [self::ENTREGADO, null, CarbonImmutable::parse($dtes->max(fn (Dte $d) => $entregados[$d->id]))];
    }

    /**
     * Los CCF entregados y desde cuándo. Una consulta para las entregas y una para los
     * albaranes, para todos juntos.
     *
     * @param  Collection<int, Dte>  $dtes  con `id`, `fecha_emision` y `numero_orden_compra`
     * @return array<int, string> dte_id => fecha
     */
    public function entregados(Collection $dtes): array
    {
        if ($dtes->isEmpty()) {
            return [];
        }

        $fechas = SalidaRutaEntrega::query()->entregadas()->whereIn('dte_id', $dtes->pluck('id'))
            ->groupBy('dte_id')->selectRaw('dte_id, MAX(fecha_resultado) as fecha')
            ->pluck('fecha', 'dte_id')
            ->map(fn ($f) => CarbonImmutable::parse($f)->toDateString())
            ->all();

        [$porDte, $porOrden] = $this->albaranes->indices($dtes->pluck('id')->all(), $dtes->pluck('numero_orden_compra')->all());
        foreach ($dtes as $dte) {
            $resolucion = $this->albaranes->elegir($porDte, $porOrden, $dte->id, $dte->numero_orden_compra);
            if (! isset($fechas[$dte->id]) && $resolucion->estaVinculado()) {
                $fechas[$dte->id] = ($resolucion->albaran->fecha_albaran ?? $dte->fecha_emision)->toDateString();
            }
        }

        return $fechas;
    }
}
