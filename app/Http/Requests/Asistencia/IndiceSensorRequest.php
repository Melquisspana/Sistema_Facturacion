<?php

namespace App\Http\Requests\Asistencia;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que el AS608 dice de sí mismo: cuántas ranuras tiene y cuáles están grabadas.
 *
 * La CAPACIDAD la manda el hardware, siempre. No hay un valor por defecto que la
 * sobreviva: los AS608 varían entre modelos (127, 162, 300, 1000…) y una constante
 * en el código sería verdad hasta el día que se instale otro sensor — y la mentira
 * se descubriría fallando un enrolamiento contra una ranura inexistente.
 *
 * `ocupadas` puede venir vacío, y eso significa «el sensor no tiene ninguna
 * plantilla», que es distinto de no haber sincronizado nunca. Esa segunda
 * situación se representa con la ausencia de la sincronización entera, no con una
 * lista vacía.
 */
class IndiceSensorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capacidad' => ['required', 'integer', 'min:1', 'max:65535'],
            'ocupadas' => ['present', 'array', 'max:65535'],
            'ocupadas.*' => ['integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ocupadas.present' => 'Falta la lista de ranuras ocupadas. Mandá un arreglo vacío si el sensor no tiene ninguna.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'motivo' => 'payload_invalido',
            'mensaje' => 'Datos inválidos',
            'errores' => $validator->errors()->toArray(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
