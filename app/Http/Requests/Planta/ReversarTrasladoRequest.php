<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversión de un traslado enviado o recibido.
 *
 * El MOTIVO es obligatorio: reversar deshace un movimiento de mercancía ya
 * registrado, y dentro de un mes será la única forma de saber por qué el saldo
 * volvió al origen. El servicio vuelve a exigirlo por su cuenta.
 */
class ReversarTrasladoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.traslados.reversar lo aplica la ruta
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
            'motivo.required' => 'Indica por qué se reversa este traslado.',
            'motivo.min' => 'El motivo debe explicar la reversión: al menos 10 caracteres.',
        ];
    }
}
