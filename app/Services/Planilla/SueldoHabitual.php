<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaSueldo;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * El sueldo habitual de cada persona, como línea de tiempo.
 *
 * ═══════ Por qué no es un número ═══════
 *
 * Un solo campo «salario» parece suficiente hasta el día que alguien sube de sueldo.
 * Ese día, cambiar el número REESCRIBE EL PASADO: reimprimir la quincena de marzo
 * saldría con el sueldo de agosto, y el papel que la persona firmó en marzo diría otra
 * cosa que el sistema.
 *
 * Por eso cada cambio es una fila con su `vigente_desde`. El habitual de un período es
 * la fila más reciente que ya estaba vigente cuando ese período empezó.
 *
 * ═══════ Lo que este servicio NO decide ═══════
 *
 * Las planillas confirmadas no consultan esto. `planilla_detalles.salario` es una foto
 * tomada al preparar y se queda como está pase lo que pase acá. Esta línea de tiempo
 * solo responde a una pregunta: **qué proponer** al abrir una quincena nueva.
 *
 * Y ajustar el importe de una quincena concreta NO toca el habitual. Son dos cosas
 * distintas: una excepción de un período y un cambio de sueldo. Confundirlas haría que
 * pagar un día de más en diciembre subiera el sueldo para siempre.
 */
final class SueldoHabitual
{
    /**
     * Registra un importe habitual vigente desde una fecha.
     *
     * Repetir el mismo día CORRIGE el importe en vez de crear una segunda verdad para
     * la misma fecha: «¿cuánto ganaba el 1 de marzo?» tiene que tener una sola
     * respuesta. El índice único de la tabla es el candado real.
     *
     * @param  array{importe: string, vigente_desde: string, motivo?: ?string}  $datos
     */
    public function registrar(User $usuario, PlanillaEmpleado $empleado, array $datos): PlanillaSueldo
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
        abort_unless($usuario->can('planilla.salarios'), 403);

        $datos = Validator::make($datos, [
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ], [
            'importe.required' => 'Escribí el importe habitual de la quincena.',
            'vigente_desde.required' => 'Indicá desde cuándo aplica este importe.',
        ])->validate();

        $centavos = Dinero::centavos($datos['importe']);

        if ($centavos < 0) {
            throw ValidationException::withMessages([
                'importe' => 'El importe habitual no puede ser negativo.',
            ]);
        }

        return DB::transaction(function () use ($usuario, $empleado, $datos, $centavos) {
            // `whereDate` y búsqueda explícita en vez de `updateOrCreate`: la columna
            // está casteada a `date` y se guarda como «2026-09-01 00:00:00», así que
            // buscarla por igualdad con «2026-09-01» NO la encuentra y el segundo
            // registro del mismo día se estrella contra el índice único en vez de
            // corregir. El índice sigue siendo el candado; esto es para que el camino
            // normal no choque con él.
            $fila = PlanillaSueldo::where('planilla_empleado_id', $empleado->id)
                ->whereDate('vigente_desde', $datos['vigente_desde'])
                ->lockForUpdate()
                ->first();

            $valores = [
                'importe' => Dinero::decimal($centavos),
                'motivo' => $datos['motivo'] ?? null,
                'registrado_por' => $usuario->id,
            ];

            if ($fila !== null) {
                $fila->update($valores);

                return $fila->fresh();
            }

            return PlanillaSueldo::create($valores + [
                'planilla_empleado_id' => $empleado->id,
                'vigente_desde' => $datos['vigente_desde'],
            ]);
        });
    }

    /**
     * Importe habitual vigente en una fecha, en centavos, o null si esa persona
     * todavía no tenía ninguno asignado ese día.
     *
     * Null NO es cero. Cero sería «esta quincena no cobra»; null es «nadie ha dicho
     * cuánto gana», y la pantalla tiene que pedirlo en vez de proponer 0.00.
     */
    public function vigenteEn(PlanillaEmpleado $empleado, string $fecha): ?int
    {
        $fila = PlanillaSueldo::where('planilla_empleado_id', $empleado->id)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();

        return $fila === null ? null : Dinero::centavos((string) $fila->importe);
    }

    /**
     * Lo mismo para MUCHA gente de una vez, que es como lo necesita la pantalla de
     * preparar: una consulta y no una por persona.
     *
     * @param  Collection<int, PlanillaEmpleado>  $empleados
     * @return array<int, int> empleado_id => centavos
     */
    public function vigentesEn(Collection $empleados, string $fecha): array
    {
        if ($empleados->isEmpty()) {
            return [];
        }

        // Una fila por persona: la de mayor `vigente_desde` dentro del alcance. Se
        // resuelve ordenando y quedándose con la primera de cada grupo, que funciona
        // igual en SQLite y en MySQL —un `DISTINCT ON` no—.
        return PlanillaSueldo::whereIn('planilla_empleado_id', $empleados->pluck('id'))
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderBy('planilla_empleado_id')
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->get(['planilla_empleado_id', 'importe'])
            ->groupBy('planilla_empleado_id')
            ->map(fn ($filas) => Dinero::centavos((string) $filas->first()->importe))
            ->all();
    }

    /**
     * Historial completo, del cambio más reciente al más antiguo. Es lo que se muestra
     * en la ficha para que se pueda comprobar de dónde salió cada cifra.
     *
     * @return Collection<int, PlanillaSueldo>
     */
    public function historial(PlanillaEmpleado $empleado): Collection
    {
        return PlanillaSueldo::where('planilla_empleado_id', $empleado->id)
            ->with('registrador')
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->get();
    }
}
