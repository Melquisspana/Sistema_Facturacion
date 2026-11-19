<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\AmbienteHacienda;
use App\Enums\TipoDte;
use App\Models\Correlativo;
use Illuminate\Contracts\Validation\Validator;
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
            // Del mismo establecimiento y vigente: la emisión busca el correlativo por los
            // dos, así que uno con el punto de venta de otro no lo encontraría nunca.
            'punto_venta_id' => [
                'nullable',
                Rule::exists('puntos_venta', 'id')
                    ->where('establecimiento_id', $this->input('establecimiento_id'))
                    ->whereNull('deleted_at'),
            ],
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
            'punto_venta_id.exists' => 'El punto de venta tiene que ser del establecimiento elegido y estar vigente.',
        ];
    }

    /**
     * Con documentos emitidos, el correlativo ya es parte de su numeración: no se baja de
     * un número usado ni se cambia lo que decide qué documentos toma.
     */
    public function withValidator(Validator $validator): void
    {
        $correlativo = $this->route('correlativo');
        if (! $correlativo instanceof Correlativo) {
            return;
        }

        $validator->after(function ($validator) use ($correlativo) {
            if (! $validator->errors()->has('ultimo_numero')) {
                $piso = $correlativo->mayorNumeroEmitido();
                if ($this->integer('ultimo_numero') < $piso) {
                    $validator->errors()->add('ultimo_numero', "No puede ser menor que {$piso}: ese número ya lo usó un documento emitido con este correlativo.");
                }
            }

            if (! $correlativo->dtes()->exists()) {
                return;
            }

            $fijos = [
                'tipo_dte' => ['el tipo de documento', $correlativo->tipo_dte->value],
                'establecimiento_id' => ['el establecimiento', $correlativo->establecimiento_id],
                'punto_venta_id' => ['el punto de venta', $correlativo->punto_venta_id],
                'ambiente' => ['el ambiente', $correlativo->ambiente->value],
            ];
            foreach ($fijos as $campo => [$nombre, $actual]) {
                if ((string) $this->input($campo) !== (string) $actual) {
                    $validator->errors()->add($campo, "Este correlativo ya tiene facturas emitidas: no se le puede cambiar {$nombre}. Si necesitás otro, creá un correlativo nuevo.");
                }
            }
        });
    }

    /** Datos validados sin el campo virtual de combinación. */
    public function datosCorrelativo(): array
    {
        return $this->safe()->except('tipo_dte_combo');
    }
}
