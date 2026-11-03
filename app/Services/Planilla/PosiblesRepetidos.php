<?php

namespace App\Services\Planilla;

use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Personas que pueden estar cargadas más de una vez, de cuando cada módulo daba su propia
 * alta. SOLO LEE: no enlaza ni borra nada. Revisar cada grupo y enlazarlo desde la ficha
 * es una decisión de una persona, no del sistema.
 *
 * Se comparan las fichas de planilla con lo que quedó suelto en Asistencia, Rutas y
 * Usuarios (lo ya enlazado a una ficha es esa misma persona). Mismo criterio que el alta
 * ({@see AltaEmpleado::coincidencias()}): mismo primer nombre y algún apellido en común, o
 * una de las dos con un solo nombre («Hernán»). Es una sospecha, no una certeza.
 */
final class PosiblesRepetidos
{
    /**
     * @return Collection<int, Collection<int, array{tipo: string, id: int, nombre: string, activo: bool}>>
     */
    public function grupos(): Collection
    {
        $planilla = PlanillaEmpleado::all();
        $enlazados = fn (string $columna) => $planilla->pluck($columna)->filter()->all();

        $personas = collect()
            ->concat($planilla->map(fn (PlanillaEmpleado $p) => $this->fila('planilla', $p->id, $p->nombre, $p->activo)))
            ->concat(AsistenciaEmpleado::whereNotIn('id', $enlazados('asistencia_empleado_id'))->get()
                ->map(fn (AsistenciaEmpleado $a) => $this->fila('asistencia', $a->id, $a->nombreCompleto(), $a->activo) + ['user_id' => $a->user_id]))
            ->concat(PersonalRuta::whereNotIn('id', $enlazados('personal_ruta_id'))->get()
                ->map(fn (PersonalRuta $r) => $this->fila('rutas', $r->id, $r->nombre, $r->activo) + ['user_id' => $r->user_id, 'asistencia_id' => $r->asistencia_empleado_id]))
            ->concat(User::whereNotIn('id', $enlazados('user_id'))->get()
                ->map(fn (User $u) => $this->fila('usuario', $u->id, $u->name, $u->activo)))
            ->values();

        // Unión simple: cada persona apunta al primer índice de su grupo.
        $grupo = range(0, max(0, $personas->count() - 1));
        $raiz = function (int $i) use (&$grupo) {
            while ($grupo[$i] !== $i) {
                $i = $grupo[$i] = $grupo[$grupo[$i]];
            }

            return $i;
        };
        foreach ($personas as $i => $a) {
            foreach ($personas as $j => $b) {
                if ($j > $i && $this->parecidas($a, $b) && ! $this->mismaPersona($a, $b)) {
                    $grupo[$raiz($j)] = $raiz($i);
                }
            }
        }

        return $personas->keys()->groupBy(fn (int $i) => $raiz($i))
            ->filter(fn (Collection $indices) => $indices->count() > 1)
            ->map(fn (Collection $indices) => $indices->map(fn (int $i) => collect($personas[$i])->only(['tipo', 'id', 'nombre', 'activo'])->all())->values())
            ->values();
    }

    /** @return array{tipo: string, id: int, nombre: string, activo: bool, partes: array<int, string>} */
    private function fila(string $tipo, int $id, string $nombre, bool $activo): array
    {
        $partes = array_values(array_filter(preg_split('/\s+/', Str::lower(Str::ascii(trim($nombre)))) ?: []));

        return ['tipo' => $tipo, 'id' => $id, 'nombre' => $nombre, 'activo' => $activo, 'partes' => $partes];
    }

    private function parecidas(array $a, array $b): bool
    {
        if ($a['partes'] === [] || $b['partes'] === [] || $a['partes'][0] !== $b['partes'][0]) {
            return false;
        }
        if (count($a['partes']) === 1 || count($b['partes']) === 1) {
            return true;
        }

        return array_intersect(array_slice($a['partes'], 1), array_slice($b['partes'], 1)) !== [];
    }

    /** Ya enlazadas entre sí, aunque ninguna esté en planilla: son la misma persona. */
    private function mismaPersona(array $a, array $b): bool
    {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($y['tipo'] === 'usuario' && ($x['user_id'] ?? null) === $y['id']) {
                return true;
            }
            if ($x['tipo'] === 'rutas' && $y['tipo'] === 'asistencia' && ($x['asistencia_id'] ?? null) === $y['id']) {
                return true;
            }
        }

        return false;
    }
}
