<?php

namespace App\Http\Requests\Dte;

use App\Enums\TipoAnulacionMh;
use App\Models\Dte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida y autoriza la TRANSMISIÓN REAL del evento de invalidación (anulardte) desde la web.
 *
 * La autorización de CANDIDATURA vive en la política
 * ({@see \App\Policies\DtePolicy::transmitirInvalidacion()}). Aquí, además, se valida en
 * SERVIDOR la frase-barrera exacta `INVALIDAR DTE` (no basta el JS) y los campos CAT-024.
 * Los candados DUROS restantes (flags, firma real, ambiente, doble invalidación, evidencia
 * protegida, NC relacionada) los RE-valida el servicio DteInvalidacionService en cada intento.
 */
class TransmitirInvalidacionRequest extends FormRequest
{
    /** Frase-barrera exacta exigida en servidor. */
    public const FRASE = 'INVALIDAR DTE';

    public function authorize(): bool
    {
        $dte = $this->route('dte');

        return $dte instanceof Dte
            && $this->user() !== null
            && $this->user()->can('transmitirInvalidacion', $dte);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(array_map(fn ($t) => $t->value, TipoAnulacionMh::cases()))],
            'motivo' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => (int) $this->input('tipo') === TipoAnulacionMh::Otro->value)],
            'reemplazo' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => (int) $this->input('tipo') === TipoAnulacionMh::ErrorInformacion->value)],
            // Frase-barrera exacta validada en SERVIDOR (defensa en profundidad, no solo JS).
            'confirmacion_invalidacion' => ['required', 'string', Rule::in([self::FRASE])],
            'confirmar_nc_relacionada' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Seleccione el tipo de anulación (CAT-024).',
            'motivo.required' => 'El motivo en texto es obligatorio para el tipo 3 (Otro).',
            'reemplazo.required' => 'El código de generación del documento de reemplazo es obligatorio para el tipo 1 (Error en la información).',
            'confirmacion_invalidacion.required' => 'Escribí la frase exacta '.self::FRASE.' para transmitir la invalidación.',
            'confirmacion_invalidacion.in' => 'La frase de confirmación no coincide: escribí exactamente '.self::FRASE.'.',
        ];
    }
}
