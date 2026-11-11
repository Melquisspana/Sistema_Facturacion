<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La pestaña «Viajes» del tablero de Rutas (etapa 6, 10/11/2026): cada viaje abierto
 * ({@see Viajes}) y en qué paso va cada pedido (sacado, en fábrica, facturado, entregado),
 * todo calculado, sin tablas nuevas. El paso y qué cuenta como entregado los decide
 * {@see PasoPedido}, la misma regla con la que el viaje se cierra solo
 * ({@see Viajes::todoEntregado()}): `todoEntregado` de cada fila vale lo mismo.
 *
 * Además de los pedidos, cada viaje dice los CCF que se anotaron en él sin salir de un pedido
 * suyo y siguen sin entregar (`sueltos`): también lo dejan abierto.
 *
 * «Trabado» es lo que lleva días sin avanzar ({@see self::DIAS_TRABADO_FABRICA} y
 * {@see self::DIAS_TRABADO_ENTREGA}). Solo en los viajes abiertos.
 *
 * Abajo van dos avisos para la oficina: las facturas que no se pudieron entregar dos veces o
 * más ({@see EntregasCcf::fallas()}) y los quedan de las tiendas que faltan cobrar
 * ({@see SalidaRutaEntrega::scopeQuedanPorCobrar()}).
 *
 * Las mismas consultas con un viaje que con veinte: los viajes con todo cargado, una para
 * las entregas y una para los albaranes.
 */
class TableroViajes
{
    public const SACADO = PasoPedido::SACADO;

    public const EN_FABRICA = PasoPedido::EN_FABRICA;

    public const FACTURADO = PasoPedido::FACTURADO;

    public const ENTREGADO = PasoPedido::ENTREGADO;

    public const NO_SE_TRAE = PasoPedido::NO_SE_TRAE;

    /** Los cuatro pasos, en orden. */
    public const PASOS = PasoPedido::PASOS;

    /** Sacado hace estos días o más y todavía sin facturar: trabado en la fábrica. */
    public const DIAS_TRABADO_FABRICA = 2;

    /** Facturado hace estos días o más y todavía sin entregar: trabado en la entrega. */
    public const DIAS_TRABADO_ENTREGA = 3;

    /** Los viajes cerrados que se muestran: los de estos últimos días. */
    public const DIAS_CERRADOS = 14;

    public function __construct(
        private readonly Viajes $viajes,
        private readonly PasoPedido $pasos,
        private readonly EntregasCcf $entregas,
    ) {}

    /**
     * @return array{abiertos: Collection<int, array>, cerrados: Collection<int, array>, fallas: Collection<int, array>, quedan: Collection<int, SalidaRutaEntrega>}
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
                // Los CCF anotados en el viaje: los que no son de un pedido suyo también esperan.
                'entregas:id,salida_ruta_id,dte_id',
                'entregas.dte' => fn ($q) => $q->withTrashed()->select('id', 'fecha_emision', 'numero_orden_compra'),
            ])
            ->orderBy('fecha_inicio')->orderBy('id')
            ->get();

        $entregados = $this->pasos->entregados($salidas->flatMap->pedidos->flatMap->envios->pluck('dte')
            ->concat($salidas->flatMap->entregas->pluck('dte'))->filter()->unique('id'));

        $filas = $salidas->map(fn (SalidaRuta $s) => $this->fila($s, $entregados, $hoy));
        [$abiertos, $cerrados] = $filas->partition(fn (array $f) => $f['salida']->estado === EstadoSalidaRuta::EnCurso);

        return [
            'abiertos' => $abiertos->values(),
            'cerrados' => $cerrados->sortByDesc(fn (array $f) => [$f['salida']->fecha_fin_real->toDateString(), $f['salida']->id])->values(),
            'fallas' => $this->fallas(),
            'quedan' => $this->quedan(),
        ];
    }

    /**
     * Las facturas que siguen por entregar y ya no se pudieron entregar
     * {@see EntregasCcf::FALLAS_AVISO} veces o más: el aviso para el jefe, las de más
     * intentos primero. Las mismas cuentas de «Por entregar».
     *
     * @return Collection<int, array{dte: Dte, veces: int}>
     */
    private function fallas(): Collection
    {
        $pendientes = $this->entregas->porEntregar();
        $fallas = array_filter($this->entregas->fallas($pendientes->modelKeys()), fn (int $n) => $n >= EntregasCcf::FALLAS_AVISO);
        if ($fallas === []) {
            return collect();
        }

        return $pendientes->whereIn('id', array_keys($fallas))->load('clienteSucursal:id,nombre')
            ->map(fn (Dte $dte) => ['dte' => $dte, 'veces' => $fallas[$dte->id]])
            ->sortByDesc('veces')->values();
    }

    /**
     * Los quedan de las tiendas que faltan cobrar, el pago más próximo primero. Sale de la
     * lista cuando «Facturas por cobrar» da la factura por pagada.
     *
     * @return Collection<int, SalidaRutaEntrega>
     */
    private function quedan(): Collection
    {
        return SalidaRutaEntrega::query()->quedanPorCobrar()
            ->with(['dte:id,numero_control,total_pagar', 'sala:id,nombre'])
            ->orderBy('id')->get();
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
        // Los CCF anotados en el viaje que no salieron de un pedido suyo y siguen sin entregar.
        $deSusPedidos = $viaje->pedidos->flatMap->envios->pluck('dte_id')->filter()->map(fn ($id) => (int) $id)->all();
        $sueltos = $viaje->entregas->pluck('dte_id')->map(fn ($id) => (int) $id)->unique()
            ->reject(fn (int $id) => in_array($id, $deSusPedidos, true) || isset($entregados[$id]))->count();

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
            'sueltos' => $sueltos,
            // La regla del cierre ({@see Viajes::todoEntregado()}), con lo ya calculado.
            'todoEntregado' => $pedidos->every(fn (array $p) => PasoPedido::terminado($p['paso']))
                && $viaje->entregas->pluck('dte_id')->every(fn ($id) => isset($entregados[(int) $id])),
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
        [$paso, $detalle, $desde] = $this->pasos->de($pedido, $entregados);

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
}
