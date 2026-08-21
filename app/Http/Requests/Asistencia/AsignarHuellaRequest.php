<?php

namespace App\Http\Requests\Asistencia;

use App\Services\Asistencia\AsignarHuella;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asociar una ranura del sensor a una persona.
 *
 * Valida FORMA, no reglas de negocio: que la ranura esté libre lo decide
 * {@see AsignarHuella}, que es quien también lo comprueba
 * cuando la asignación llega desde la consola. Duplicar aquí esa consulta daría
 * dos respuestas a la misma pregunta y solo una de las dos estaría respaldada por
 * el único de la base.
 *
 * El rango 0..65535 es el mismo que acepta el endpoint del lector: cubre
 * cualquier AS608 sin tener que conocer el modelo, y el cero se admite porque las
 * librerías del sensor numeran las ranuras desde ahí.
 */
class AsignarHuellaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asistencia_dispositivo_id' => ['required', 'integer', 'exists:asistencia_dispositivos,id'],
            'fingerprint_id' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'asistencia_dispositivo_id' => 'lector',
            'fingerprint_id' => 'número de ranura',
        ];
    }
}
