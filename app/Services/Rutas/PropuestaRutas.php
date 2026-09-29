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

        $coberturas = RutaCobertura::query()
            ->whereHas('ruta', fn ($q) => $q->where('activa', true))
            ->with('ruta:id,nombre')
            ->get();

        return $this->indice = [
            'distritos' => $coberturas->whereNotNull('distrito_id')->mapWithKeys(fn ($c) => [$c->distrito_id => $c->ruta])->all(),
            'departamentos' => $coberturas->whereNotNull('departamento_id')->mapWithKeys(fn ($c) => [$c->departamento_id => $c->ruta])->all(),
        ];
    }
}
