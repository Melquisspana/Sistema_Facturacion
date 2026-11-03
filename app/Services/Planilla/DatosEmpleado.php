<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Los datos de la persona se editan en un solo lugar: la ficha de Personal.
 *
 * Nombre, documento, cargo y fecha de ingreso se guardan una vez y llegan solos a
 * Asistencia y Rutas. Quien marca escribe nombres y apellidos por separado, porque
 * Asistencia los guarda así; el resto escribe el nombre completo.
 *
 * Las quincenas confirmadas no cambian: su nombre y su documento son una foto del pago.
 */
final class DatosEmpleado
{
    public function __construct(private NombreUnico $nombres) {}

    public function actualizar(User $actor, PlanillaEmpleado $empleado, array $datos): PlanillaEmpleado
    {
        abort_unless($actor->activo && $actor->can('planilla.gestionar'), 403);
        $marca = $empleado->asistencia_empleado_id !== null;
        if ($marca) {
            abort_unless($actor->can('asistencia.gestionar'), 403);
        }

        $reglas = [
            'documento_tipo' => ['required', 'string', Rule::in(array_keys(PlanillaEmpleado::DOCUMENTO_TIPOS))],
            'dui' => ['bail', 'required', 'string', 'max:20'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'alta_el' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
        $reglas['dui'] = array_merge($reglas['dui'], AltaEmpleado::reglasDocumento($datos, $empleado->id));
        $reglas += $marca
            ? ['nombres' => ['required', 'string', 'max:80'], 'apellidos' => ['required', 'string', 'max:80']]
            : ['nombre' => ['required', 'string', 'max:120']];

        $datos = Validator::make($datos, $reglas, [
            'dui.unique' => 'Ya existe una persona en planilla con ese tipo y número de documento.',
            'alta_el.before_or_equal' => 'La fecha de ingreso no puede ser futura.',
        ])->validate();

        return DB::transaction(function () use ($actor, $empleado, $datos, $marca) {
            $empleado = PlanillaEmpleado::whereKey($empleado->id)->lockForUpdate()->firstOrFail();
            $empleado->update([
                'documento_tipo' => $datos['documento_tipo'], 'dui' => $datos['dui'],
                'cargo' => $datos['cargo'] ?? null, 'alta_el' => $datos['alta_el'] ?? null,
            ]);

            if ($marca) {
                $asistencia = $empleado->asistencia;
                $asistencia->update([
                    'nombres' => trim($datos['nombres']), 'apellidos' => trim($datos['apellidos']),
                    'fecha_ingreso' => $datos['alta_el'] ?? $asistencia->fecha_ingreso,
                ]);
                $this->nombres->desdeAsistencia($asistencia);
            } else {
                $this->nombres->cambiar($actor, $empleado, ['nombre' => $datos['nombre']]);
            }

            activity('empleado')->performedOn($empleado)->causedBy($actor)->log('editó los datos del empleado');

            return $empleado->fresh();
        });
    }
}
