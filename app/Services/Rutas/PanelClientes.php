<?php

namespace App\Services\Rutas;

use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\Ruta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * «Clientes» de Rutas (Melqui, 28/11/2026): todas las salas activas, con lo último que se les
 * sacó y su último CCF, y la historia de cada una, para cobrar y para ver qué se le dejó.
 * Solo lectura y SIN MONTOS: fechas, quién, cantidades y si se entregó. El PDF del CCF sí trae
 * montos (aprobado así) y se abre aparte.
 *
 * Las reglas no se repiten acá: qué CCF cuentan y si se entregó lo dice {@see EntregasCcf},
 * qué hay por cobrar {@see HistoriaSala::porCobrar()} y la zona de una sala {@see PropuestaRutas}
 * (la del tablero). Las mismas consultas para una página de 2 salas que de 25.
 */
class PanelClientes
{
    public const POR_PAGINA = 25;

    /** De a cuántos pedidos y CCF trae la historia; «Ver más» suma otros tantos. */
    public const HISTORIAL = 10;

    public const ENTREGADO = 'entregado';

    public const POR_ENTREGAR = 'por_entregar';

    /**
     * Sin entrega anotada y fuera de la ventana de «Por entregar»: de antes del control de
     * entregas (`rutas.entregas_desde`) o de hace más de {@see EntregasCcf::DIAS_POR_ENTREGAR}
     * días. No sale en «Por entregar» del vendedor, así que no se dice «por entregar».
     */
    public const SIN_REGISTRO = 'sin_registro';

    /** Lo que hace falta de cada CCF: número, fecha y OC (para el albarán). Nada de montos. */
    private const DEL_CCF = ['id', 'cliente_sucursal_id', 'numero_control', 'numero_orden_compra', 'fecha_emision'];

    public function __construct(
        private readonly EntregasCcf $entregas,
        private readonly HistoriaSala $historia,
        private readonly PropuestaRutas $propuesta,
    ) {}

    /** @return Collection<int, Ruta> las zonas activas, para el filtro: las mismas que reparten las salas */
    public function zonas(): Collection
    {
        return $this->propuesta->activas();
    }

    /**
     * Las salas activas por nombre, cada una con su zona (la del tablero; null si ninguna la
     * cubre). `$buscar` mira el nombre y el código sin tildes ni mayúsculas, como el tablero.
     *
     * @return Collection<int, array{sala: ClienteSucursal, zona: ?Ruta}>
     */
    public function salas(string $buscar = '', ?int $zonaId = null): Collection
    {
        $norm = fn (?string $s) => Str::lower(Str::ascii((string) $s));

        return ClienteSucursal::query()->where('activo', true)->orderBy('nombre')->orderBy('id')
            ->get(['id', 'nombre', 'codigo', 'ruta_id', 'distrito_id', 'departamento_id'])
            ->map(fn (ClienteSucursal $sala) => ['sala' => $sala, 'zona' => $this->propuesta->zonaDe($sala->ruta_id, $sala->distrito_id, $sala->departamento_id)])
            ->filter(fn (array $f) => ($zonaId === null || $f['zona']?->id === $zonaId)
                && ($buscar === '' || str_contains($norm($f['sala']->nombre), $norm($buscar)) || str_contains($norm($f['sala']->codigo), $norm($buscar))))
            ->values();
    }

    /**
     * Lo de cada sala de la página: su último pedido (quién y cuánto de cada producto), su
     * último CCF y si se entregó, y cuántas facturas tiene por cobrar.
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, array{pedido: ?PedidoSala, ccf: ?Dte, entrega: ?array{estado: string, fecha: ?Carbon}, porCobrar: int}>
     */
    public function resumen(array $salaIds): array
    {
        if ($salaIds === []) {
            return [];
        }

        $pedidos = self::ultimoPorSala(PedidoSala::vigentes(), 'fecha', $salaIds)->load(self::delPedido());
        $ccf = self::ultimoPorSala($this->entregas->baseCcf()->select(self::DEL_CCF), 'fecha_emision', $salaIds);
        $entregas = $this->entregas($ccf);
        $porCobrar = $this->historia->porCobrar($salaIds);

        $resumen = [];
        foreach ($salaIds as $id) {
            $dte = $ccf->get($id);
            $resumen[$id] = [
                'pedido' => $pedidos->get($id),
                'ccf' => $dte,
                'entrega' => $dte ? $entregas[$dte->id] : null,
                'porCobrar' => count($porCobrar[$id] ?? []),
            ];
        }

        return $resumen;
    }

    /**
     * La historia de una sala: sus pedidos vigentes y sus CCF, los más nuevos primero, de a
     * {@see HISTORIAL} por cada «Ver más» (`$paginasPedidos` y `$paginasCcf`, desde 1).
     *
     * @return array{pedidos: EloquentCollection<int, PedidoSala>, masPedidos: bool, ccf: EloquentCollection<int, Dte>, masCcf: bool, entregas: array<int, array{estado: string, fecha: ?Carbon}>, porCobrar: list<int>}
     */
    public function historia(ClienteSucursal $sala, int $paginasPedidos, int $paginasCcf): array
    {
        // Uno de más, para saber si hay «Ver más» sin contar todos.
        $pedidos = PedidoSala::vigentes()->where('cliente_sucursal_id', $sala->id)->with(self::delPedido())
            ->orderByDesc('fecha')->orderByDesc('id')->limit($paginasPedidos * self::HISTORIAL + 1)->get();
        $ccf = $this->entregas->baseCcf()->where('cliente_sucursal_id', $sala->id)->select(self::DEL_CCF)
            ->orderByDesc('fecha_emision')->orderByDesc('id')->limit($paginasCcf * self::HISTORIAL + 1)->get();
        $masPedidos = $pedidos->count() > $paginasPedidos * self::HISTORIAL;
        $masCcf = $ccf->count() > $paginasCcf * self::HISTORIAL;
        $ccf = $ccf->take($paginasCcf * self::HISTORIAL);

        return [
            'pedidos' => $pedidos->take($paginasPedidos * self::HISTORIAL),
            'masPedidos' => $masPedidos,
            'ccf' => $ccf,
            'masCcf' => $masCcf,
            'entregas' => $this->entregas($ccf),
            'porCobrar' => $this->historia->porCobrar([$sala->id])[$sala->id] ?? [],
        ];
    }

    /** Lo que se muestra de cada pedido: quién lo sacó y cuánto de cada producto, en el orden en que se pidió. */
    private static function delPedido(): array
    {
        return ['sacadoPor:id,nombre', 'lineas' => fn ($q) => $q->orderBy('id'), 'lineas.producto:id,nombre'];
    }

    /**
     * Cómo va la entrega de cada CCF: entregado (con su día, si se sabe), por entregar (está
     * en «Por entregar» del vendedor) o sin entrega anotada ({@see SIN_REGISTRO}).
     *
     * @param  Collection<int, Dte>  $dtes
     * @return array<int, array{estado: string, fecha: ?Carbon}> dte_id => entrega
     */
    private function entregas(Collection $dtes): array
    {
        $entregados = $this->entregas->entregados($dtes);
        $desde = $this->entregas->desdePorEntregar();

        return $dtes->mapWithKeys(fn (Dte $dte) => [$dte->id => match (true) {
            $entregados[$dte->id]['entregado'] => ['estado' => self::ENTREGADO, 'fecha' => $entregados[$dte->id]['fecha']],
            filled($dte->fecha_emision) && Carbon::parse($dte->fecha_emision)->lt($desde) => ['estado' => self::SIN_REGISTRO, 'fecha' => null],
            default => ['estado' => self::POR_ENTREGAR, 'fecha' => null],
        }])->all();
    }

    /**
     * El más nuevo de cada sala en `$base`: el de la fecha más nueva y, ese día, el último que
     * se cargó. Una consulta para todas las salas.
     *
     * @template TModelo of Model
     *
     * @param  Builder<TModelo>  $base
     * @param  array<int, int>  $salaIds
     * @return EloquentCollection<int, TModelo> sala_id => registro
     */
    private static function ultimoPorSala(Builder $base, string $fecha, array $salaIds): EloquentCollection
    {
        $tabla = $base->getModel()->getTable();
        $ultimas = (clone $base)->whereIn($tabla.'.cliente_sucursal_id', $salaIds)->groupBy($tabla.'.cliente_sucursal_id')
            ->toBase()->select([])->selectRaw($tabla.'.cliente_sucursal_id as sala_id, MAX('.$tabla.'.'.$fecha.') as ultima');
        $columnas = $base->getQuery()->columns ?? ['*'];

        return (clone $base)
            ->select(array_map(fn ($c) => is_string($c) && ! str_contains($c, '.') ? $tabla.'.'.$c : $c, $columnas))
            ->joinSub($ultimas, 'ultimas', fn ($join) => $join->on('ultimas.sala_id', '=', $tabla.'.cliente_sucursal_id')
                ->on('ultimas.ultima', '=', $tabla.'.'.$fecha))
            ->orderByDesc($tabla.'.id')
            ->get()
            ->unique('cliente_sucursal_id')
            ->keyBy('cliente_sucursal_id');
    }
}
