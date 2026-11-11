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
 * ¿A qué zona toca ir? Una fila por ruta activa, que hace de ZONA (ver {@see tablero()}):
 * su última visita, cuántos días pasaron y cómo va contra el RITMO de la zona, calculado
 * con los pedidos de sus salas ({@see RitmoPedidos}). Los días aproximados que una zona ya
 * tuviera cargados (`frecuencia_objetivo_dias`) quedan solo de respaldo, mientras no tenga
 * ritmo; desde el 10/11/2026 ya no se cargan ni se editan.
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

    public function __construct(
        private readonly RitmoPedidos $pedidos,
        private readonly EntregasCcf $entregas,
        private readonly PropuestaRutas $propuesta,
    ) {}

    /**
     * Una fila por ZONA. Las rutas activas son el catálogo de zonas: la zona de una sala es
     * su ruta, si está activa, o la que cubre su distrito o su departamento
     * ({@see PropuestaRutas::para()}). Así una sala sin ruta no queda afuera.
     *
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
        return $this->tablero($hoy)['zonas'];
    }

    /**
     * Las zonas de {@see porRuta()} y TODAS las salas activas con su ritmo
     * ({@see filaSala()}) y su zona (`ruta`, null si ninguna ruta cubre su lugar).
     *
     * @return array{zonas: Collection<int, array>, salas: Collection<int, array>}
     */
    public function tablero(?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::today();

        $rutas = Ruta::activas()->orderBy('nombre')->get();
        $activas = $rutas->keyBy('id');

        // Todas las salidas que cuentan, en una consulta; se reparten en PHP.
        $salidas = SalidaRuta::query()
            ->whereIn('ruta_id', $rutas->pluck('id'))
            ->where('estado', '!=', EstadoSalidaRuta::Cancelada->value)
            ->with('personal:id,nombre')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get()
            ->groupBy('ruta_id');

        // Todas las salas activas, cada una en su zona, y su ritmo: unas pocas consultas más.
        $todas = ClienteSucursal::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'ruta_id', 'nombre', 'codigo', 'departamento_id', 'distrito_id']);
        $zonaDe = [];
        foreach ($todas as $sala) {
            $cubre = $this->propuesta->para($sala);
            $zonaDe[$sala->id] = ($sala->ruta_id !== null ? $activas->get($sala->ruta_id) : null)
                ?? ($cubre !== null ? $activas->get($cubre->id) : null);
        }
        $salas = $todas->groupBy(fn (ClienteSucursal $s) => $zonaDe[$s->id]?->id ?? 0);
        $salaIds = $todas->pluck('id')->all();
        $ritmoSalas = $this->pedidos->porSala($salaIds, $hoy);
        $visitas = $this->visitas($salaIds);

        $orden = [self::ATRASADA => 0, self::PRONTO => 1, self::SIN_SALIDAS => 2, self::EN_RUTA => 3, self::PROGRAMADA => 3, self::AL_DIA => 4, self::SIN_RITMO => 5];

        $zonas = $rutas
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

        return [
            'zonas' => $zonas,
            'salas' => $todas->map(fn (ClienteSucursal $sala) => self::filaSala($sala, $ritmoSalas[$sala->id], $hoy, $visitas[$sala->id])
                + ['ruta' => $zonaDe[$sala->id]]),
        ];
    }

    /**
     * La última entrega y el último pedido sacado de cada sala: unas pocas consultas
     * agregadas para todas juntas. La entrega es la más nueva entre el último CCF facturado
     * y la última entrega conocida en Rutas ({@see EntregasCcf::ultimaVisitaPorSala()}).
     *
     * @param  array<int, int>  $salaIds
     * @return array<int, array{entrega: ?CarbonImmutable, pedido: ?CarbonImmutable}>
     */
    private function visitas(array $salaIds): array
    {
        if ($salaIds === []) {
            return [];
        }

        $entregadas = $this->entregas->ultimaVisitaPorSala($salaIds);
        // El ritmo ya mezcla pedidos y CCF: el último CCF se lee aparte.
        $facturadas = $this->entregas->baseCcf()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->groupBy('cliente_sucursal_id')
            ->toBase()
            ->selectRaw('cliente_sucursal_id, MAX(fecha_emision) as ultima')
            ->pluck('ultima', 'cliente_sucursal_id');
        $pedidos = PedidoSala::vigentes()
            ->whereIn('cliente_sucursal_id', $salaIds)
            ->groupBy('cliente_sucursal_id')
            ->selectRaw('cliente_sucursal_id, MAX(fecha) as ultima')
            ->pluck('ultima', 'cliente_sucursal_id');

        $visitas = [];
        foreach ($salaIds as $id) {
            $entrega = isset($entregadas[$id]) ? CarbonImmutable::instance($entregadas[$id])->startOfDay() : null;
            $facturada = isset($facturadas[$id]) ? CarbonImmutable::parse($facturadas[$id])->startOfDay() : null;
            $visitas[$id] = [
                'entrega' => self::masNueva($facturada, $entrega),
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
     * El ritmo y la última visita de estas salas, con la regla del tablero ({@see filaSala()}).
     * Es la ÚNICA fuente de «Le toca»: la usan el tablero, el buscador de «Sacar pedido» y la
     * historia de la sala en el sugerido. Las mismas consultas para una sala que para veinte.
     *
     * @param  Collection<int, ClienteSucursal>  $salas
     * @return array<int, array> sala_id => fila de {@see filaSala()}
     */
    public function deSalas(Collection $salas, ?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::today();
        $ids = $salas->modelKeys();
        $ritmos = $this->pedidos->porSala($ids, $hoy);
        $visitas = $this->visitas($ids);

        return $salas->mapWithKeys(fn (ClienteSucursal $sala) => [$sala->id => self::filaSala($sala, $ritmos[$sala->id], $hoy, $visitas[$sala->id])])->all();
    }

    /** ¿Le toca visita? Pronto o atrasada, según su ritmo. */
    public static function leToca(array $fila): bool
    {
        return in_array($fila['estado'], [Ritmo::PRONTO, Ritmo::ATRASADA], true);
    }

    /**
     * Una sala con su ritmo a una fecha.
     *
     * Con `$visita` (la de {@see porRuta()}), los días se cuentan desde la última visita:
     * la entrega o el pedido sacado más nuevo. Sin ella, desde el último pedido del ritmo.
     *
     * @param  array{entrega: ?CarbonImmutable, pedido: ?CarbonImmutable}|null  $visita
     * @return array{sala: ClienteSucursal, ritmo: Ritmo, estado: string, dias: ?int, faltan: ?int, dejoDeComprar: bool, ultimaEntrega: ?CarbonImmutable, ultimoPedido: ?CarbonImmutable, proxima: ?CarbonImmutable}
     */
    public static function filaSala(ClienteSucursal $sala, Ritmo $ritmo, CarbonImmutable $dia, ?array $visita = null): array
    {
        $entrega = $visita === null ? $ritmo->ultima() : $visita['entrega'];
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
            // Cuándo toca volver: la última visita más su ritmo.
            'proxima' => $ritmo->dias !== null && $ultimaVisita !== null ? $ultimaVisita->addDays($ritmo->dias) : null,
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
