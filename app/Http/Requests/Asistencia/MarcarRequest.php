<?php

namespace App\Http\Requests\Asistencia;

use App\Enums\Asistencia\ResultadoMarcacion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Lo ÚNICO que el dispositivo tiene derecho a decidir: qué ranura del sensor
 * reconoció. Ni el empleado, ni la hora, ni el tipo de marcación: si vinieran en
 * el cuerpo, alguien en la red podría inventarlos, y por eso no se leen aunque
 * lleguen.
 *
 * El rango 0..65535 cubre cualquier AS608 (capacidad real ~162 plantillas) sin
 * tener que saber de antemano el modelo; el cero se acepta porque las librerías
 * del sensor numeran las ranuras desde ahí.
 */
class MarcarRequest extends FormRequest
{
    /** La autorización es del middleware `dispositivo.asistencia`, no de acá. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fingerprint_id' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function fingerprintId(): int
    {
        return (int) $this->validated('fingerprint_id');
    }

    /**
     * Un fallo de validación tiene que verse igual que los demás desenlaces: el
     * firmware ramifica sobre `estado` y no debería tener que entender el formato
     * de errores de Laravel para saber que el payload venía mal.
     */
    protected function failedValidation(Validator $validator): void
    {
        $estado = ResultadoMarcacion::PayloadInvalido;

        throw new HttpResponseException(response()->json([
            'ok' => false,
            'estado' => $estado->value,
            'mensaje' => 'Datos inválidos',
            'errores' => $validator->errors()->toArray(),
        ], $estado->httpStatus()));
    }
}
