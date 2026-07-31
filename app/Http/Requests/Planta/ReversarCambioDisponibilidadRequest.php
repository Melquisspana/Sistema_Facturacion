<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversión de un cambio de disponibilidad confirmado.
 *
 * El MOTIVO es obligatorio y no es un trámite: reversar devuelve saldo a
 * `retenido`, y dentro de un mes la única forma de entender por qué volvió a
 * estar fuera de la operación será leer esto. El servicio vuelve a exigirlo por
 * su cuenta: este formulario no es la única puerta.
 */
class ReversarCambioDisponibilidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.calidad.gestionar lo aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Indica por qué se reversa este cambio de disponibilidad.',
            'motivo.min' => 'El motivo debe explicar la reversión: al menos 10 caracteres.',
        ];
    }
}
