<?php

namespace App\Http\Requests\Asistencia;

use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Services\Asistencia\ResolverEnrolamiento;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que el lector reporta al terminar: grabó, o no pudo.
 *
 * ─────────── El lector solo puede alegar lo que él puede ver ───────────
 *
 * `motivo` se restringe a {@see MotivoFalloEnrolamiento::reportablesPorElLector()}.
 * Los motivos que decide el SERVIDOR —«expirada», «la ranura ya está asignada»,
 * «el empleado dejó de estar activo»— no se aceptan por esta vía: si un lector
 * pudiera declararlos, podría cerrar una orden alegando algo que no observó.
 *
 * `fingerprint_id` solo se pide en el éxito, y {@see ResolverEnrolamiento}
 * lo compara contra la ranura reservada. Le dijimos exactamente dónde grabar: si
 * dice haber grabado en otro sitio, no se asocia nada.
 *
 * El ÍNDICE DEL SENSOR viaja opcionalmente junto al fallo por conflicto de ranura.
 * Es lo que permite que el reintento siguiente ya excluya la plantilla heredada
 * que se acaba de descubrir, en vez de volver a chocar con ella.
 */
class ResultadoEnrolamientoRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:64'],
            'exito' => ['required', 'boolean'],

            'fingerprint_id' => ['required_if:exito,true,1', 'nullable', 'integer', 'min:0', 'max:65535'],

            'motivo' => [
                'required_if:exito,false,0', 'nullable',
                Rule::in(MotivoFalloEnrolamiento::reportablesPorElLector()),
            ],
            'detalle' => ['nullable', 'string', 'max:255'],

            // Foto del sensor, opcional. Llega sobre todo con `ranura_ocupada_en_sensor`.
            'indice.capacidad' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'indice.ocupadas' => ['nullable', 'array', 'max:65535'],
            'indice.ocupadas.*' => ['integer', 'min:0', 'max:65535'],
        ];
    }

    public function motivo(): MotivoFalloEnrolamiento
    {
        return MotivoFalloEnrolamiento::from((string) $this->input('motivo'));
    }

    /** @return array{capacidad: int, ocupadas: array<int, int>}|null */
    public function indiceSensor(): ?array
    {
        $capacidad = $this->input('indice.capacidad');

        if ($capacidad === null) {
            return null;
        }

        return [
            'capacidad' => (int) $capacidad,
            'ocupadas' => array_map('intval', (array) $this->input('indice.ocupadas', [])),
        ];
    }

    /**
     * Un fallo de validación tiene que verse igual que el resto del contrato: el
     * firmware ramifica sobre `motivo` y no debería tener que entender el formato
     * de errores de Laravel para saber que mandó algo mal.
     */
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
