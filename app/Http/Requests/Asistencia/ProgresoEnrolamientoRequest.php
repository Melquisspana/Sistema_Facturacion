<?php

namespace App\Http\Requests\Asistencia;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * En qué paso de la captura va el lector.
 *
 * Es informativo: sirve para que quien está mirando la pantalla web sepa si la
 * persona ya puso el dedo o si el sensor sigue esperando, y para que la orden no
 * expire mientras alguien tarda en colocarlo. **No puede completar ni fallar
 * nada** — para eso está el resultado.
 *
 * Las etapas son una lista CERRADA porque el texto acaba en la pantalla y en la
 * auditoría; una etapa libre dejaría que el firmware escribiera ahí lo que
 * quisiera.
 */
class ProgresoEnrolamientoRequest extends FormRequest
{
    /** Los pasos del AS608, en orden. */
    public const ETAPAS = [
        'esperando_dedo' => 'Esperando que coloque el dedo',
        'primera_captura' => 'Primera captura tomada',
        'retire_dedo' => 'Pidiendo que retire el dedo',
        'segunda_captura' => 'Segunda captura tomada',
        'guardando' => 'Guardando la plantilla en el sensor',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'etapa' => ['required', Rule::in(array_keys(self::ETAPAS))],
        ];
    }

    /** El texto que verá quien mira la pantalla web. */
    public function etapaLegible(): string
    {
        return self::ETAPAS[$this->input('etapa')];
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
