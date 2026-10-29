<?php

namespace App\Http\Requests\Asistencia;

use App\Enums\Asistencia\ResultadoMarcacion;
use App\Services\Asistencia\HoraOficial;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * El dispositivo identifica la ranura del sensor y puede enviar el instante de
 * una marca sin red. El servidor valida ese instante y decide empleado y tipo.
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
            'marcado_en' => ['sometimes', 'required', 'integer', function ($attribute, $value, $fail) {
                $ahora = app(HoraOficial::class)->instante()->getTimestamp();
                $maxDias = max(0, (int) config('asistencia.diferidas_max_dias', 31));
                if (is_bool($value) || ! is_scalar($value) || ! is_numeric($value)
                    || $value > $ahora + 300 || $value < $ahora - $maxDias * 86400) {
                    $fail('La hora de la marcación está fuera del rango permitido.');
                }
            }],
        ];
    }

    public function fingerprintId(): int
    {
        return (int) $this->validated('fingerprint_id');
    }

    public function instante(): ?Carbon
    {
        $epoch = $this->validated('marcado_en');

        return $epoch === null ? null : Carbon::createFromTimestamp((int) $epoch, (string) config('app.timezone'));
    }

    /**
     * Un fallo de validación tiene que verse igual que los demás desenlaces: el
     * firmware ramifica sobre `estado` y no debería tener que entender el formato
     * de errores de Laravel para saber que el payload venía mal.
     */
    protected function failedValidation(Validator $validator): void
    {
        $estado = $validator->errors()->has('marcado_en')
            ? ResultadoMarcacion::HoraInvalida
            : ResultadoMarcacion::PayloadInvalido;

        throw new HttpResponseException(response()->json([
            'ok' => false,
            'estado' => $estado->value,
            'mensaje' => 'Datos inválidos',
            'errores' => $validator->errors()->toArray(),
            'repetida' => false,
            'diferida' => $this->exists('marcado_en'),
        ], $estado->httpStatus()));
    }
}
