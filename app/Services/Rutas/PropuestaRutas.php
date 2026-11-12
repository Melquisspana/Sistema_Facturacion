<?php

namespace App\Services\Rutas;

use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use Illuminate\Support\Collection;

/**
 * Qué ruta le corresponde a cada sala según la COBERTURA de las rutas.
 *
 * La regla, en orden:
 *
 *   1. Si el DISTRITO de la sala está en la cobertura de una ruta, esa ruta.
 *   2. Si no, si su DEPARTAMENTO completo está en la cobertura de una ruta, esa ruta.
 *   3. Si no, ninguna: la sala queda «sin cobertura» y se asigna a mano.
 *
 * El distrito gana porque es más preciso: San Vicente puede cubrir Cabañas completo y
 * otra ruta llevarse Ilobasco. Solo cuentan las rutas ACTIVAS: una ruta desactivada no
 * sale, así que no puede reclamar salas.
 *
 * SOLO LECTURA. Proponer no asigna: la asignación la confirma el usuario.
 */
class PropuestaRutas
{
    /** @var array{distritos: array<int, Ruta>, departamentos: array<int, Ruta>}|null */
    private ?array $indice = null;

    /** @var Collection<int, Ruta>|null */
    private ?Collection $activas = null;

    /** Las rutas activas (las zonas), por nombre. Una sola consulta por instancia. */
    public function activas(): Collection
    {
        return $this->activas ??= Ruta::activas()->orderBy('nombre')->get();
    }

    /**
     * La zona de una sala, como la cuenta el tablero: su ruta, si está activa; si no, la que
     * cubre su distrito o su departamento; si ninguna, `null` («Sin zona»). Recibe los tres
     * ids sueltos para servir también a las consultas que no cargan el modelo de la sala.
     */
    public function zonaDe(?int $rutaId, ?int $distritoId, ?int $departamentoId): ?Ruta
    {
        $indice = $this->indice();

        return ($rutaId !== null ? $this->activas()->find($rutaId) : null)
            ?? $indice['distritos'][$distritoId] ?? $indice['departamentos'][$departamentoId] ?? null;
    }

    /** La ruta que la cobertura le propone a la sala, o `null` si ninguna la cubre. */
    public function para(ClienteSucursal $sala): ?Ruta
    {
        $indice = $this->indice();

        return $indice['distritos'][$sala->distrito_id] ?? $indice['departamentos'][$sala->departamento_id] ?? null;
    }

    /**
     * Las salas activas, repartidas según lo que la cobertura dice de ellas.
     *
     *  - `proponer`: sin ruta y con una ruta propuesta. Es lo que se asigna de un clic.
     *  - `distintas`: ya tienen ruta, pero la cobertura propone otra. Nunca se mueven
     *    solas: puede ser una excepción a propósito.
     *  - `sinCobertura`: sin ruta y sin propuesta, agrupadas por departamento. Dice qué
     *    falta cubrir.
     *  - `coinciden`: cuántas ya están donde la cobertura dice.
     *
     * @return array{
     *     proponer: Collection<int, array{sala: ClienteSucursal, ruta: Ruta}>,
     *     distintas: Collection<int, array{sala: ClienteSucursal, ruta: Ruta}>,
     *     sinCobertura: Collection<int, array{departamento: string, salas: int}>,
     *     coinciden: int,
     * }
     */
    public function clasificar(?int $rutaId = null): array
    {
        $salas = ClienteSucursal::query()
            ->where('activo', true)
            ->with(['cliente:id,nombre', 'ruta:id,nombre', 'departamento:id,nombre', 'distrito:id,nombre'])
            ->orderBy('nombre')
            ->get();

        $proponer = collect();
        $distintas = collect();
        $sinCobertura = collect();
        $coinciden = 0;

        foreach ($salas as $sala) {
            $propuesta = $this->para($sala);

            if ($propuesta === null) {
                if ($sala->ruta_id === null) {
                    $sinCobertura->push($sala);
                }

                continue;
            }

            if ($sala->ruta_id === $propuesta->id) {
                $coinciden++;
            } elseif ($rutaId === null || $propuesta->id === $rutaId) {
                ($sala->ruta_id === null ? $proponer : $distintas)->push(['sala' => $sala, 'ruta' => $propuesta]);
            }
        }

        return [
            'proponer' => $proponer,
            'distintas' => $distintas,
            'sinCobertura' => $sinCobertura
                ->groupBy(fn (ClienteSucursal $s) => $s->departamento?->nombre ?? 'Sin departamento')
                ->map(fn (Collection $grupo, string $departamento) => ['departamento' => $departamento, 'salas' => $grupo->count()])
                ->sortByDesc('salas')
                ->values(),
            'coinciden' => $coinciden,
        ];
    }

    /** @return array{distritos: array<int, Ruta>, departamentos: array<int, Ruta>} */
    private function indice(): array
    {
        if ($this->indice !== null) {
            return $this->indice;
        }

        // Solo la cobertura de las rutas activas, con la ruta tomada de {@see activas()}: dos
        // consultas en total (las rutas y las coberturas), haya las zonas que haya.
        $activas = $this->activas()->getDictionary();
        $coberturas = RutaCobertura::query()->whereIn('ruta_id', array_keys($activas))->get(['ruta_id', 'distrito_id', 'departamento_id']);

        return $this->indice = [
            'distritos' => $coberturas->whereNotNull('distrito_id')->mapWithKeys(fn ($c) => [$c->distrito_id => $activas[$c->ruta_id]])->all(),
            'departamentos' => $coberturas->whereNotNull('departamento_id')->mapWithKeys(fn ($c) => [$c->departamento_id => $activas[$c->ruta_id]])->all(),
        ];
    }
}
