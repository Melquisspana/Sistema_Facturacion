<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\AmbienteHacienda;
use App\Enums\TipoDte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrelativoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $correlativoId = $this->route('correlativo')?->id;

        return [
            'tipo_dte' => ['required', 'in:'.implode(',', array_column(TipoDte::habilitados(), 'value'))],
            'establecimiento_id' => ['required', 'exists:establecimientos,id'],
            'punto_venta_id' => ['nullable', 'exists:puntos_venta,id'],
            'ambiente' => ['required', 'in:'.implode(',', array_column(AmbienteHacienda::cases(), 'value'))],
            'serie' => ['nullable', 'string', 'max:10'],
            'ultimo_numero' => ['required', 'integer', 'min:0'],
            'activo' => ['required', 'boolean'],

            // Unicidad de la combinación, ignorando el propio registro al editar.
            'tipo_dte_combo' => [
                Rule::unique('correlativos', 'tipo_dte')
                    ->where(fn ($q) => $q
                        ->where('tipo_dte', $this->tipo_dte)
                        ->where('establecimiento_id', $this->establecimiento_id)
                        ->where('punto_venta_id', $this->punto_venta_id)
                        ->where('ambiente', $this->ambiente))
                    ->ignore($correlativoId),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
            // Campo virtual solo para disparar la regla de unicidad de combinación.
            'tipo_dte_combo' => $this->tipo_dte,
        ]);
    }

    public function messages(): array
    {
        return [
            'tipo_dte_combo.unique' => 'Ya existe un correlativo para esa combinación de tipo de DTE, establecimiento, punto de venta y ambiente.',
        ];
    }

    /** Datos validados sin el campo virtual de combinación. */
    public function datosCorrelativo(): array
    {
        return $this->safe()->except('tipo_dte_combo');
    }
}
