<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ¿A qué ruta toca ir? Una fila por ruta activa: su última salida, cuántos días pasaron
 * y cómo va contra el RITMO de la ruta, calculado con los pedidos de sus salas
 * ({@see RitmoPedidos}). Los días aproximados cargados a mano (`frecuencia_objetivo_dias`)
 * se usan solo de respaldo, mientras la ruta no tenga ritmo (decisión 4 del plan).
 *
 * Esto avisa, no agenda: no hay calendario ni fecha obligatoria. El semáforo es el de
 * {@see Ritmo::estado()}, más:
 *
 *   · en_ruta     — hay una salida en curso.
 *   · sin_ritmo   — ni ritmo calculado ni días cargados: se muestran los días, sin color.
 *   · sin_salidas — nunca se registró una salida ni hay pedidos de sus salas.
 *
 * Los días se cuentan desde el REGRESO de la última salida finalizada (o su inicio si no
 * se anotó regreso). Una salida cancelada no cuenta: nadie fue. Si la ruta nunca tuvo
 * una salida, se cuentan desde el comienzo de la última vuelta de pedidos.
 */
class RitmoRutas
{
    public const EN_RUTA = 'en_ruta';

    public const ATRASADA = Ritmo::ATRASADA;

    public const PRONTO = Ritmo::PRONTO;

    public const AL_DIA = Ritmo::AL_DIA;

    public const SIN_RITMO = Ritmo::SIN_RITMO;

    public const SIN_SALIDAS = 'sin_salidas';

    /** El ritmo de la ruta salió de los días cargados a mano. */
    public const MANUAL = 'manual';

    public function __construct(private readonly RitmoPedidos $pedidos) {}

    /**
     * @return Collection<int, array{
     *     ruta: Ruta,
     *     estado: string,
     *     dias: ?int,
     *     ultima: ?SalidaRuta,
     *     enCurso: ?SalidaRuta,
     *     proxima: ?SalidaRuta,
     *     faltan: ?int,
     *     avance: ?int,
     *     ritmo: ?int,
     *     origenRitmo: ?string,
     *     ritmoSalas: array<int, Ritmo>,
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
            ->get(['id', 'ruta_id'])
            ->groupBy('ruta_id');
        $ritmoSalas = $this->pedidos->porSala($salas->flatten()->pluck('id')->all(), $hoy);

        $orden = [self::ATRASADA => 0, self::PRONTO => 1, self::SIN_SALIDAS => 2, self::EN_RUTA => 3, self::AL_DIA => 4, self::SIN_RITMO => 5];

        return $rutas
            ->map(fn (Ruta $ruta) => $this->fila(
                $ruta,
                $salidas->get($ruta->id, collect()),
                array_intersect_key($ritmoSalas, array_flip($salas->get($ruta->id, collect())->pluck('id')->all())),
                $hoy,
            ))
            ->sortBy([
                fn ($a, $b) => $orden[$a['estado']] <=> $orden[$b['estado']],
                fn ($a, $b) => ($b['dias'] ?? -1) <=> ($a['dias'] ?? -1),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, SalidaRuta>  $salidas
     * @param  array<int, Ritmo>  $ritmoSalas  sala_id => ritmo, solo las de esta ruta
     */
    private function fila(Ruta $ruta, Collection $salidas, array $ritmoSalas, CarbonImmutable $hoy): array
    {
        $enCurso = $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::EnCurso);
        $ultima = $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Finalizada);
        $proxima = $salidas
            ->filter(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Planificada)
            ->sortBy('fecha_inicio')
            ->first();

        $calculado = $this->pedidos->deRuta($ritmoSalas);

        $dias = null;
        if ($ultima !== null) {
            $regreso = CarbonImmutable::parse($ultima->fecha_fin_real ?? $ultima->fecha_inicio)->startOfDay();
            $dias = max(0, (int) $regreso->diffInDays($hoy));
        } elseif ($calculado->ultima() !== null) {
            $dias = $calculado->diasDesde($hoy);
        }

        // Manda el ritmo calculado; los días cargados a mano, solo si no hay.
        $ritmo = $calculado->dias ?? $ruta->frecuencia_objetivo_dias;
        $origen = match (true) {
            $calculado->dias !== null => $calculado->confianza(),
            (bool) $ruta->frecuencia_objetivo_dias => self::MANUAL,
            default => null,
        };

        $estado = match (true) {
            $enCurso !== null => self::EN_RUTA,
            $dias === null => self::SIN_SALIDAS,
            ! $ritmo => self::SIN_RITMO,
            default => Ritmo::estado($ritmo, $dias),
        };

        return [
            'ruta' => $ruta,
            'estado' => $estado,
            'dias' => $dias,
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
        ];
    }
}
