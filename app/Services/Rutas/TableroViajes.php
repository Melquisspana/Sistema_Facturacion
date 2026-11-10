<?php

namespace App\Services\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La pestaña «Viajes» del tablero de Rutas (etapa 6, 10/11/2026): cada viaje abierto
 * ({@see Viajes}) y en qué paso va cada pedido, todo calculado, sin tablas nuevas:
 *
 *   1. Sacado: registrado y la fábrica todavía no lo tocó.
 *   2. En fábrica: en armado, mandado en parte, o mandado y todavía sin un CCF que cuente
 *      (nunca facturado, o su CCF se invalidó, rechazó o borró).
 *   3. Facturado: todo lo mandado está en CCF que cuentan (`pedido_sala_envios.dte_id`).
 *   4. Entregado: cada uno de esos CCF tiene su entrega registrada (en cualquier salida) o
 *      un albarán de entrega inequívoco ({@see AlbaranLocalizador}).
 *
 * Un pedido con partes en pasos distintos va en el de la parte más atrasada. Lo que la
 * fábrica cerró sin mandar («No se trae») va aparte; lo anulado no cuenta.
 *
 * «Trabado» es lo que lleva días sin avanzar ({@see self::DIAS_TRABADO_FABRICA} y
 * {@see self::DIAS_TRABADO_ENTREGA}). Solo en los viajes abiertos.
 *
 * Las mismas consultas con un viaje que con veinte: los viajes con todo cargado, una para
 * las entregas y una para los albaranes.
 */
class TableroViajes
{
    public const SACADO = 'sacado';

    public const EN_FABRICA = 'en_fabrica';

    public const FACTURADO = 'facturado';

    public const ENTREGADO = 'entregado';

    public const NO_SE_TRAE = 'no_se_trae';

    /** Los cuatro pasos, en orden. */
    public const PASOS = [self::SACADO, self::EN_FABRICA, self::FACTURADO, self::ENTREGADO];

    /** Sacado hace estos días o más y todavía sin facturar: trabado en la fábrica. */
    public const DIAS_TRABADO_FABRICA = 2;

    /** Facturado hace estos días o más y todavía sin entregar: trabado en la entrega. */
    public const DIAS_TRABADO_ENTREGA = 3;

    /** Los viajes cerrados que se muestran: los de estos últimos días. */
    public const DIAS_CERRADOS = 14;

    public function __construct(private readonly Viajes $viajes, private readonly AlbaranLocalizador $albaranes) {}

    /**
     * @return array{abiertos: Collection<int, array>, cerrados: Collection<int, array>}
     */
    public function calcular(?string $hoy = null): array
    {
        $hoy ??= HoraNegocio::fechaHoy();
        $desde = CarbonImmutable::parse($hoy)->subDays(self::DIAS_CERRADOS)->toDateString();

        $salidas = SalidaRuta::automaticas()
            ->where('proposito', '!=', PropositoSalida::Entregar->value)
            ->where(fn ($q) => $q->where('estado', EstadoSalidaRuta::EnCurso->value)
                ->orWhere(fn ($q) => $q->where('estado', EstadoSalidaRuta::Finalizada->value)->whereDate('fecha_fin_real', '>=', $desde)))
            ->with([
                'ruta:id,nombre',
                'personal:id,nombre',
                'pedidos' => fn ($q) => $q->vigentes()->orderBy('fecha')->orderBy('id'),
                'pedidos.sala:id,nombre',
                'pedidos.ruta:id,nombre',
                'pedidos.sacadoPor:id,nombre',
                'pedidos.envios' => fn ($q) => $q->vigentes()->orderBy('numero'),
                'pedidos.envios.dte:id,estado,deleted_at,fecha_emision,numero_orden_compra',
            ])
            ->orderBy('fecha_inicio')->orderBy('id')
            ->get();

        $entregados = $this->entregados($salidas->flatMap->pedidos->flatMap->envios->pluck('dte')->filter()->unique('id'));

        $filas = $salidas->map(fn (SalidaRuta $s) => $this->fila($s, $entregados, $hoy));
        [$abiertos, $cerrados] = $filas->partition(fn (array $f) => $f['salida']->estado === EstadoSalidaRuta::EnCurso);

        return [
            'abiertos' => $abiertos->values(),
            'cerrados' => $cerrados->sortByDesc(fn (array $f) => [$f['salida']->fecha_fin_real->toDateString(), $f['salida']->id])->values(),
        ];
    }

    /**
     * @param  array<int, string>  $entregados  dte_id => fecha de la entrega
     */
    private function fila(SalidaRuta $viaje, array $entregados, string $hoy): array
    {
        $abierto = $viaje->estado === EstadoSalidaRuta::EnCurso;
        $pedidos = $viaje->pedidos
            ->map(fn (PedidoSala $p) => $this->paso($p, $entregados, $hoy, $abierto))
            ->sortBy(fn (array $p) => [array_search($p['paso'], [...self::PASOS, self::NO_SE_TRAE], true), $p['pedido']->fecha->toDateString(), $p['pedido']->id])
            ->values();

        $conteo = array_fill_keys(self::PASOS, 0);
        foreach ($pedidos as $p) {
            if (isset($conteo[$p['paso']])) {
                $conteo[$p['paso']]++;
            }
        }

        return [
            'salida' => $viaje,
            'nombre' => $viaje->nombreViaje(),
            'dia' => $abierto ? $this->viajes->dia($viaje, $hoy) : null,
            'vendedores' => $viaje->personal->pluck('nombre')
                ->merge($viaje->pedidos->map(fn (PedidoSala $p) => $p->sacadoPor?->nombre))
                ->filter()->unique()->sort()->values()->all(),
            'salas' => $viaje->pedidos->pluck('cliente_sucursal_id')->unique()->count(),
            'conteo' => $conteo,
            'noSeTrae' => $pedidos->where('paso', self::NO_SE_TRAE)->count(),
            'trabados' => $pedidos->whereNotNull('trabado')->count(),
            'pedidos' => $pedidos,
        ];
    }

    /**
     * En qué paso va el pedido, desde cuándo y si está trabado.
     *
     * @param  array<int, string>  $entregados
     * @return array{pedido: PedidoSala, paso: string, detalle: ?string, desde: CarbonImmutable, dias: int, trabado: ?string}
     */
    private function paso(PedidoSala $pedido, array $entregados, string $hoy, bool $abierto): array
    {
        $sacado = CarbonImmutable::parse($pedido->fecha->toDateString());
        [$paso, $detalle, $desde] = match (true) {
            $pedido->estado === EstadoPedidoSala::NoSeTrae => [self::NO_SE_TRAE, 'La fábrica no lo trae', $sacado],
            $pedido->estaRegistrado() && $pedido->armando_desde === null => [self::SACADO, null, $sacado],
            $pedido->estaPorMandar() => [self::EN_FABRICA, $pedido->estaRegistrado() ? 'En armado' : 'Mandado en parte', $sacado],
            default => $this->despuesDeLaFabrica($pedido, $entregados, $sacado),
        };

        $dias = (int) $desde->diffInDays(CarbonImmutable::parse($hoy));
        $trabado = match (true) {
            ! $abierto => null,
            in_array($paso, [self::SACADO, self::EN_FABRICA], true) && $dias >= self::DIAS_TRABADO_FABRICA => 'Sacado hace '.$dias.' días y todavía '
                .($pedido->estaPorMandar() ? 'no sale de la fábrica.' : 'no se factura.'),
            $paso === self::FACTURADO && $dias >= self::DIAS_TRABADO_ENTREGA => 'Facturado hace '.$dias.' días y todavía no se entrega.',
            default => null,
        };

        return ['pedido' => $pedido, 'paso' => $paso, 'detalle' => $detalle, 'desde' => $desde, 'dias' => $dias, 'trabado' => $trabado];
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
     * Los CCF entregados y desde cuándo: con la entrega registrada, en cualquier salida, o
     * con su albarán de entrega. Una consulta para las entregas y una para los albaranes.
     *
     * @param  Collection<int, Dte>  $dtes
     * @return array<int, string> dte_id => fecha
     */
    private function entregados(Collection $dtes): array
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
