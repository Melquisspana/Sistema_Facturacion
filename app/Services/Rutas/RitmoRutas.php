<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ¿A qué ruta toca ir? Una fila por ruta activa: su última salida, cuántos días pasaron
 * y cómo va contra sus días objetivo.
 *
 * La frecuencia es APROXIMADA —la llevan de memoria los vendedores— y por eso esto
 * avisa, no agenda: no hay calendario ni fecha obligatoria. El semáforo:
 *
 *   · en_ruta     — hay una salida en curso.
 *   · atrasada    — pasaron más días que el objetivo.
 *   · pronto      — ya va por el 80 % del objetivo.
 *   · al_dia      — todavía falta.
 *   · sin_objetivo— la ruta no tiene días objetivo: se muestran los días, sin color.
 *   · sin_salidas — nunca se registró una salida.
 *
 * Los días se cuentan desde el REGRESO de la última salida finalizada (o su inicio si no
 * se anotó regreso). Una salida cancelada no cuenta: nadie fue.
 */
class RitmoRutas
{
    public const EN_RUTA = 'en_ruta';

    public const ATRASADA = 'atrasada';

    public const PRONTO = 'pronto';

    public const AL_DIA = 'al_dia';

    public const SIN_OBJETIVO = 'sin_objetivo';

    public const SIN_SALIDAS = 'sin_salidas';

    /** Desde qué fracción del objetivo se avisa que ya casi toca. */
    private const UMBRAL_PRONTO = 0.8;

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

        $orden = [self::ATRASADA => 0, self::PRONTO => 1, self::SIN_SALIDAS => 2, self::EN_RUTA => 3, self::AL_DIA => 4, self::SIN_OBJETIVO => 5];

        return $rutas
            ->map(fn (Ruta $ruta) => $this->fila($ruta, $salidas->get($ruta->id, collect()), $hoy))
            ->sortBy([
                fn ($a, $b) => $orden[$a['estado']] <=> $orden[$b['estado']],
                fn ($a, $b) => ($b['dias'] ?? -1) <=> ($a['dias'] ?? -1),
            ])
            ->values();
    }

    /** @param  Collection<int, SalidaRuta>  $salidas */
    private function fila(Ruta $ruta, Collection $salidas, CarbonImmutable $hoy): array
    {
        $enCurso = $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::EnCurso);
        $ultima = $salidas->first(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Finalizada);
        $proxima = $salidas
            ->filter(fn (SalidaRuta $s) => $s->estado === EstadoSalidaRuta::Planificada)
            ->sortBy('fecha_inicio')
            ->first();

        $dias = null;
        if ($ultima !== null) {
            $regreso = CarbonImmutable::parse($ultima->fecha_fin_real ?? $ultima->fecha_inicio)->startOfDay();
            $dias = max(0, (int) $regreso->diffInDays($hoy));
        }

        $objetivo = $ruta->frecuencia_objetivo_dias;

        $estado = match (true) {
            $enCurso !== null => self::EN_RUTA,
            $ultima === null => self::SIN_SALIDAS,
            ! $objetivo => self::SIN_OBJETIVO,
            $dias > $objetivo => self::ATRASADA,
            $dias >= $objetivo * self::UMBRAL_PRONTO => self::PRONTO,
            default => self::AL_DIA,
        };

        return [
            'ruta' => $ruta,
            'estado' => $estado,
            'dias' => $dias,
            'ultima' => $ultima,
            'enCurso' => $enCurso,
            'proxima' => $proxima,
            // Días que faltan para el objetivo; negativo = días de atraso.
            'faltan' => $objetivo && $dias !== null ? $objetivo - $dias : null,
            // Cuánto del objetivo ya pasó, de 0 a 100, para la barra.
            'avance' => $objetivo && $dias !== null ? (int) min(100, round($dias * 100 / $objetivo)) : null,
        ];
    }
}
