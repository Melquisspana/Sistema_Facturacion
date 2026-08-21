<?php

namespace App\Http\Requests\Asistencia;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una persona que marca asistencia.
 *
 * NO acepta `user_id`. El enlace con la cuenta del sistema existe en el esquema y
 * es opcional, pero atarlo desde este formulario dejaría que quien administra
 * personal asocie a cualquiera con cualquier usuario —y con él, con sus permisos
 * fiscales—. Cuando haga falta, será su propia pantalla con su propio permiso.
 *
 * `activo` tampoco: se cambia con su acción dedicada, que es un acto aparte y
 * auditable por sí mismo, no un checkbox perdido en un formulario de nombres.
 */
class EmpleadoRequest extends FormRequest
{
    /** La autorización la resuelve el middleware `permission:asistencia.gestionar`. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            // Único cuando viene, pero opcional: hoy no todos tienen código de
            // planilla y exigirlo obligaría a inventarlos.
            'codigo' => [
                'nullable', 'string', 'max:30',
                Rule::unique('asistencia_empleados', 'codigo')->ignore($this->route('empleado')),
            ],
            'fecha_ingreso' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nombres' => 'nombres',
            'apellidos' => 'apellidos',
            'codigo' => 'código de planilla',
            'fecha_ingreso' => 'fecha de ingreso',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'codigo.unique' => 'Ya hay otra persona con ese código de planilla.',
        ];
    }
}
