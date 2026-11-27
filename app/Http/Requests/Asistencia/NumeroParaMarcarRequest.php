<?php

namespace App\Http\Requests\Asistencia;

use App\Services\Asistencia\NumeroParaMarcar;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Poner o cambiar el número para marcar desde la ficha.
 *
 * Valida FORMA. Que nadie más lo tenga lo decide {@see NumeroParaMarcar::asignar()},
 * respaldado por el único de la base, igual que las ranuras con AsignarHuella.
 * El permiso lo pone la ruta (`asistencia.gestionar`), como a las huellas.
 */
class NumeroParaMarcarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'numero' => ['required', 'string', 'regex:'.NumeroParaMarcar::PATRON],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'numero.required' => 'Escribí el número de 4 dígitos.',
            'numero.string' => 'El número para marcar tiene que tener exactamente 4 dígitos.',
            'numero.regex' => 'El número para marcar tiene que tener exactamente 4 dígitos.',
        ];
    }
}
