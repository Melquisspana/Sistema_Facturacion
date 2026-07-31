<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversión de un ajuste confirmado.
 *
 * El MOTIVO es obligatorio y es distinto del motivo del ajuste original: aquel
 * explica por qué cambió el saldo, este explica por qué esa explicación era
 * equivocada. El servicio vuelve a exigirlo por su cuenta.
 */
class ReversarAjusteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.ajustes.crear lo aplica la ruta
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
            'motivo.required' => 'Indica por qué se reversa este ajuste.',
            'motivo.min' => 'El motivo debe explicar la reversión: al menos 10 caracteres.',
        ];
    }
}
