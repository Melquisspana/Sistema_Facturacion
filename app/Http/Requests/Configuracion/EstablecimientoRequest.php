<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\TipoEstablecimiento;
use App\Models\Establecimiento;
use App\Support\Ubicacion\CoherenciaUbicacion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstablecimientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $establecimientoId = $this->route('establecimiento')?->id;

        return [
            'empresa_id' => ['required', 'exists:empresas,id'],
            'codigo' => [
                'required', 'string', 'max:4',
                // Como lo acepta Hacienda en el número de control (DTE-03-M001P001-…).
                'regex:/^[MBSP][0-9]{3}$/',
                // La base tampoco deja repetir el de uno borrado: al crearlo se recupera ese
                // ({@see EstablecimientoController::store()}), y al editar otro se avisa abajo.
                Rule::unique('establecimientos', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $this->empresa_id))
                    ->withoutTrashed()
                    ->ignore($establecimientoId),
            ],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_establecimiento' => ['nullable', Rule::in(array_column(TipoEstablecimiento::cases(), 'value'))],
            'pais_id' => ['nullable', 'exists:paises,id'],
            'departamento_id' => ['required', 'exists:departamentos,id'],
            // El municipio (catálogo previo) es opcional; la ubicación legal la da el distrito.
            'municipio_id' => [
                'nullable',
                Rule::exists('municipios', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ],
            // Distrito (división 2024): OBLIGATORIO en el emisor (configuración completa);
            // debe pertenecer al departamento seleccionado.
            'distrito_id' => [
                'required',
                Rule::exists('distritos', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.regex' => 'El código tiene que ser M, B, S o P y tres números, como M001: así lo pide Hacienda en el número de control.',
            'codigo.unique' => 'Ese código ya lo tiene otro establecimiento de esta empresa.',
            'municipio_id.exists' => 'El municipio seleccionado no pertenece al departamento elegido.',
            'distrito_id.required' => 'El distrito es obligatorio para el establecimiento del emisor.',
            'distrito_id.exists' => 'El distrito seleccionado no pertenece al departamento elegido.',
            'departamento_id.required' => 'El departamento es obligatorio.',
            'tipo_establecimiento.in' => 'El tipo de establecimiento debe ser un valor válido del catálogo CAT-009.',
        ];
    }

    /**
     * Coherencia del trío departamento → municipio 2024 → distrito del establecimiento
     * emisor: el distrito debe pertenecer al municipio elegido, no solo al departamento.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->hasAny(['departamento_id', 'municipio_id', 'distrito_id'])) {
                return;
            }

            $problema = CoherenciaUbicacion::problema(
                $this->integer('departamento_id') ?: null,
                $this->integer('municipio_id') ?: null,
                $this->integer('distrito_id') ?: null,
            );

            if ($problema !== null) {
                $validator->errors()->add('distrito_id', $problema);
            }
        });

        // El código va en el número de control de cada documento, y anular uno viejo usa
        // el código de hoy: con documentos numerados no se cambia. Y con cualquier documento,
        // borradores incluidos, no se pasa a otra empresa: cada uno es del emisor que lo hizo.
        $establecimiento = $this->route('establecimiento');
        if ($establecimiento instanceof Establecimiento) {
            $validator->after(function ($validator) use ($establecimiento) {
                if (! $validator->errors()->has('codigo') && Establecimiento::onlyTrashed()
                    ->where('empresa_id', $this->input('empresa_id'))
                    ->where('codigo', $this->input('codigo'))
                    ->exists()) {
                    $validator->errors()->add('codigo', 'Ese código era de un establecimiento que se borró. Para volver a usarlo, creá un establecimiento con ese código: se recupera el que se borró.');
                }

                if ((string) $this->input('codigo') !== (string) $establecimiento->codigo && $establecimiento->tieneDocumentos(soloConNumero: true)) {
                    $validator->errors()->add('codigo', 'Este establecimiento ya tiene facturas: el código no se puede cambiar, porque va en el número de control de cada una.');
                }

                if ((string) $this->input('empresa_id') !== (string) $establecimiento->empresa_id && $establecimiento->tieneDocumentos()) {
                    $validator->errors()->add('empresa_id', 'Este establecimiento ya tiene facturas o borradores: no se puede pasar a otra empresa.');
                }
            });
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
        ]);
    }
}
