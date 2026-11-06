<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ¿A qué ruta toca ir? Una fila por ruta activa: su última visita, cuántos días pasaron
 * y cómo va contra el RITMO de la ruta, calculado con los pedidos de sus salas
 * ({@see RitmoPedidos}). Los días aproximados cargados a mano (`frecuencia_objetivo_dias`)
 * se usan solo de respaldo, mientras la ruta no tenga ritmo (decisión 4 del plan).
 *
 * Esto avisa, no agenda: no hay calendario ni fecha obligatoria. El semáforo es el de
 * {@see Ritmo::estado()}, más:
 *
 *   · en_ruta     — hay una salida en curso.
 *   · programada  — ya hay una salida armada (planificada) para la ruta: no se avisa como
 *                   atrasada ni «toca pronto», porque ya se resolvió.
 *   · sin_ritmo   — ni ritmo calculado ni días cargados: se muestran los días, sin color.
 *   · sin_salidas — nunca se registró una salida ni hay visitas a sus salas.
 *
 * Los días se cuentan desde la ÚLTIMA VISITA a la ruta: el regreso de la última salida
 * finalizada (o su inicio si no se anotó regreso) o la última visita a cualquiera de sus
 * salas, lo que sea más nuevo. Una salida cancelada no cuenta: nadie fue.
 *
 * La última visita a una sala es su última entrega (el último CCF facturado o la última
 * entrega marcada en Rutas, la más nueva) o el último pedido sacado, lo que sea más nuevo:
 * con el pedido ya sacado no toca volver a sacarlo.
 */
class RitmoRutas
{
    public const EN_RUTA = 'en_ruta';

    public const PROGRAMADA = 'programada';

    public const ATRASADA = Ritmo::ATRASADA;

    public const PRONTO = Ritmo::PRONTO;

    public const AL_DIA = Ritmo::AL_DIA;

    public const SIN_RITMO = Ritmo::SIN_RITMO;

    public const SIN_SALIDAS = 'sin_salidas';

    /** El ritmo de la ruta salió de los días cargados a mano. */
    public const MANUAL = 'manual';

    /** Los días se cuentan desde el regreso de la última salida. */
    public const DESDE_SALIDA = 'salida';

    /** Los días se cuentan desde la última visita a una de sus salas. */
    public const DESDE_SALAS = 'salas';

    public function __construct(private readonly RitmoPedidos $pedidos, private readonly EntregasCcf $entregas) {}

    /**
     * @return Collection<int, array{
     *     ruta: Ruta,
     *     estado: string,
     *     dias: ?int,
     *     desde: ?string,
     *     ultima: ?SalidaRuta,
     *     enCurso: ?SalidaRuta,
     *     proxima: ?SalidaRuta,
     *     faltan: ?int,
     *     avance: ?int,
     *     ritmo: ?int,
     *     origenRitmo: ?string,
     *     ritmoSalas: array<int, Ritmo>,
     *     visitas: array<int, array{entrega: ?CarbonImmutable, pedido: ?CarbonImmutable}>,
     *     salas: Collection<int, ClienteSucursal>,
     * }>
     */
    public function porRuta(?CarbonImmutable $hoy = null): Collection
    {
        $hoy ??= CarbonImmutable::today();

        $rutas = Ruta::activas()->withCount('sucursales')->orderBy('nombre')->get();

        // Todas las salidas que cuentan, en una consulta; se reparten en PHP.
        $salidas = SalidaRuta::query()
            ->whereIn('ruta_id', $rutas->pluck('id'))
            ->where('estado', '!=', EstadoSalidaRuta::Cancelada->value)
            ->with('personal:id,nombre')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get()
            ->groupBy('ruta_id');

        // Las salas activas de cada ruta y su ritmo: dos consultas más, para todas.
        $salas = ClienteSucursal::query()
            ->where('activo', true)
            ->whereIn('ruta_id', $rutas->pluck('id'))
            ->orderBy('nombre')
            ->get(['id', 'ruta_id', 'nombre', 'codigo'])
            ->groupBy('ruta_id');
        $salaIds = $salas->flatten()->pluck('id')->all();
        $ritmoSalas = $this->pedidos->porSala($salaIds, $hoy);
        $visitas = $this->visitas($salaIds, $ritmoSalas);

        $orden = [self::ATRASADA => 0, self::PRONTO => 1, self::SIN_SALIDAS => 2, self::EN_RUTA => 3, self::PROGRAMADA => 3, self::AL_DIA => 4, self::SIN_RITMO => 5];

        return $rutas
            ->map(function (Ruta $ruta) use ($salas, $salidas, $ritmoSalas, $visitas, $hoy) {
                $deRuta = array_flip($salas->get($ruta->id, collect())->pluck('id')->all());

                return $this->fila(
                    $ruta,
                    $salidas->get($ruta->id, collect()),
                    array_intersect_key($ritmoSalas, $deRuta),
                    array_intersect_key($visitas, $deRuta),
                    $hoy,
                ) + ['salas' => $salas->get($ruta->id, collect())];
            })
            ->sortBy([
                fn ($a, $b) => $orden[$a['estado']] <=> $orden[$b['estado']],
                // La más atrasada (o la que toca antes) arriba.
                fn ($a, $b) => ($a['faltan'] ?? PHP_INT_MAX) <=> ($b['faltan'] ?? PHP_INT_MAX),
                fn ($a, $b) => ($b['dias'] ?? -1) <=> ($a['dias'] ?? -1),
            ])
            ->values();
    }

    /**
     * La última entrega y el último pedido sacado de cada sala: tres consultas agregadas
     * para todas juntas. La entrega es la más nueva entre el último CCF facturado (el
     * comienzo del último pedido del ritmo) y la última entrega conocida en Rutas
     * ({@see EntregasCcf::ultimaVisitaPorSala()}).
     *
     * @param  array<int, int>  $salaIds
     * @param  array<int, Ritmo>  $ritmoSalas
     * @return array<int, array{entrega: ?CarbonImmutable, pedido: ?CarbonImmutable}>
     */
    private function visitas(array $salaIds, array $ritmoSalas): array
    {
        if ($salaIds === []) {
            return [];
        }

        $entregadas = $this->entregas->ultimaVisitaPorSala($salaIds);
        $pedidos = PedidoSala::registrados()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->groupBy('cliente_sucursal_id')
            ->selectRaw('cliente_sucursal_id, MAX(fecha) as ultima')
            ->pluck('ultima', 'cliente_sucursal_id');

        $visitas = [];
        foreach ($salaIds as $id) {
            $entrega = isset($entregadas[$id]) ? CarbonImmutable::instance($entregadas[$id])->startOfDay() : null;
            $visitas[$id] = [
                'entrega' => self::masNueva($ritmoSalas[$id]?->ultima(), $entrega),
                'pedido' => isset($pedidos[$id]) ? CarbonImmutable::parse($pedidos[$id])->startOfDay() : null,
            ];
        }

        return $visitas;
    }

    private static function masNueva(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $a->max($b),
        };
    }

    /**
     * Las salas activas de UNA ruta con su ritmo al día dado, las más urgentes primero.
     * «Tu próxima salida» lo pide para el día de la salida: qué salas tocan sacar pedido.
     *
     * @return Collection<int, array{sala: ClienteSucursal, ritmo: Ritmo, estado: string, dias: ?int, faltan: ?int, dejoDeComprar: bool}>
     */
    public function salasDeRuta(Ruta $ruta, CarbonImmutable $dia): Collection
    {
        $salas = ClienteSucursal::query()
            ->where('activo', true)
            ->where('ruta_id', $ruta->id)
            ->orderBy('nombre')
            ->get(['id', 'ruta_id', 'nombre', 'codigo']);
        $ritmos = $this->pedidos->porSala($salas->pluck('id')->all(), $dia);

        return self::ordenarSalas($salas->map(fn (ClienteSucursal $sala) => self::filaSala($sala, $ritmos[$sala->id], $dia)));
    }

    /**
     * Una sala con su ritmo a una fecha. La comparten el tablero y «Tu próxima salida».
     *
     * Con `$visita` (la de {@see porRuta()}), los días se cuentan desde la última visita:
     * la entrega o el pedido sacado más nuevo. Sin ella, desde el último pedido del ritmo.
     *
     * @param  array{entrega: ?CarbonImmutable, pedido: ?CarbonImmutable}|null  $visita
     * @return array{sala: ClienteSucursal, ritmo: Ritmo, estado: string, dias: ?int, faltan: ?int, dejoDeComprar: bool, ultimaEntrega: ?CarbonImmutable, ultimoPedido: ?CarbonImmutable}
     */
    public static function filaSala(ClienteSucursal $sala, Ritmo $ritmo, CarbonImmutable $dia, ?array $visita = null): array
    {
        $entrega = $visita['entrega'] ?? $ritmo->ultima();
        $pedido = $visita['pedido'] ?? null;
        $ultimaVisita = self::masNueva($entrega, $pedido);
        $dias = $ultimaVisita === null ? null : max(0, (int) $ultimaVisita->diffInDays($dia->startOfDay()));

        return [
            'sala' => $sala,
            'ritmo' => $ritmo,
            'estado' => $ritmo->dias === null ? Ritmo::SIN_RITMO : Ritmo::estado($ritmo->dias, (int) $dias),
            'dias' => $dias,
            // Días para la próxima visita esperada; negativo = días de atraso.
            'faltan' => $ritmo->dias !== null ? $ritmo->dias - $dias : null,
            'dejoDeComprar' => $ritmo->dejoDeComprar($dia, $dias),
            'ultimaEntrega' => $entrega,
            'ultimoPedido' => $pedido,
        ];
    }

    /**
     * Las atrasadas primero (la más atrasada arriba), después las que tocan antes, al
     * final las que no tienen ritmo.
     *
     * @param  Collection<int, array>  $filas  de {@see filaSala()}
     * @return Collection<int, array>
     */
    public static function ordenarSalas(Collection $filas): Collection
    {
        $orden = [Ritmo::ATRASADA => 0, Ritmo::PRONTO => 1, Ritmo::AL_DIA => 2, Ritmo::SIN_RITMO => 3];

        return $filas
            ->sortBy([
                fn ($a, $b) => $orden[$a['estado']] <=> $orden[$b['estado']],
                fn ($a, $b) => ($a['faltan'] ?? PHP_INT_MAX) <=> ($b['faltan'] ?? PHP_INT_MAX),
                fn ($a, $b) => ($b['dias'] ?? -1) <=> ($a['dias'] ?? -1),
                fn ($a, $b) => strcmp($a['sala']->nombre, $b['sala']->nombre),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, SalidaRuta>  $salidas
     * @param  array<int, Ritmo>  $ritmoSalas  sala_id => ritmo, solo las de esta ruta
     * @param  array<int, array{entrega: ?CarbonImmutable, pedido: ?CarbonImmutable}>  $visitas  ídem
     */
    private function fila(Ruta $ruta, Collection $salidas, array $ritmoSalas, array $visitas, CarbonImmutable $hoy): array
    {
        $enCurso = $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::EnCurso);
        $ultima = $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Finalizada);
        $proxima = $salidas
            ->filter(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Planificada)
            ->sortBy('fecha_inicio')
            ->first();

        $calculado = $this->pedidos->deRuta($ritmoSalas);

        // La última visita: el regreso de la última salida o la visita más nueva a una de sus salas.
        $regreso = $ultima === null ? null : CarbonImmutable::parse($ultima->fecha_fin_real ?? $ultima->fecha_inicio)->startOfDay();
        $enSalas = null;
        foreach ($visitas as $visita) {
            $enSalas = self::masNueva($enSalas, self::masNueva($visita['entrega'], $visita['pedido']));
        }
        $visto = self::masNueva($regreso, $enSalas);
        $dias = $visto === null ? null : max(0, (int) $visto->diffInDays($hoy->startOfDay()));
        $desde = match (true) {
            $visto === null => null,
            $regreso !== null && $regreso->gte($visto) => self::DESDE_SALIDA,
            default => self::DESDE_SALAS,
        };

        // Manda el ritmo calculado; los días cargados a mano, solo si no hay.
        $ritmo = $calculado->dias ?? $ruta->frecuencia_objetivo_dias;
        $origen = match (true) {
            $calculado->dias !== null => $calculado->confianza(),
            (bool) $ruta->frecuencia_objetivo_dias => self::MANUAL,
            default => null,
        };

        $estado = match (true) {
            $enCurso !== null => self::EN_RUTA,
            $proxima !== null => self::PROGRAMADA,
            $dias === null => self::SIN_SALIDAS,
            ! $ritmo => self::SIN_RITMO,
            default => Ritmo::estado($ritmo, $dias),
        };

        return [
            'ruta' => $ruta,
            'estado' => $estado,
            'dias' => $dias,
            'desde' => $desde,
            'ultima' => $ultima,
            'enCurso' => $enCurso,
            'proxima' => $proxima,
            // Días que faltan para la fecha esperada; negativo = días de atraso.
            'faltan' => $ritmo && $dias !== null ? $ritmo - $dias : null,
            // Cuánto del ritmo ya pasó, de 0 a 100, para la barra.
            'avance' => $ritmo && $dias !== null ? (int) min(100, round($dias * 100 / $ritmo)) : null,
            'ritmo' => $ritmo ?: null,
            // confiable, aproximado, manual o null (sin ritmo).
            'origenRitmo' => $origen,
            'ritmoSalas' => $ritmoSalas,
            'visitas' => $visitas,
        ];
    }
}
