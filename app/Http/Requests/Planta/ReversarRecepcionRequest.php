<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversión de una recepción confirmada.
 *
 * El MOTIVO es obligatorio y no es un trámite: reversar deshace inventario ya
 * contabilizado, y dentro de un mes la única forma de entender por qué el saldo
 * bajó será leer esto. Se exige una longitud mínima para que «error» no cuente
 * como explicación.
 *
 * El servicio vuelve a exigirlo por su cuenta: este formulario no es la única
 * puerta.
 */
class ReversarRecepcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.recepciones.reversar lo aplica la ruta
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
            'motivo.required' => 'Indica por qué se reversa esta recepción.',
            'motivo.min' => 'El motivo debe explicar la reversión: al menos 10 caracteres.',
        ];
    }
}
