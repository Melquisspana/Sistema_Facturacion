<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\TipoEstablecimiento;
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
                Rule::unique('establecimientos', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $this->empresa_id))
                    ->whereNull('deleted_at')
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
            'municipio_id.exists' => 'El municipio seleccionado no pertenece al departamento elegido.',
            'distrito_id.required' => 'El distrito es obligatorio para el establecimiento del emisor.',
            'distrito_id.exists' => 'El distrito seleccionado no pertenece al departamento elegido.',
            'departamento_id.required' => 'El departamento es obligatorio.',
            'tipo_establecimiento.in' => 'El tipo de establecimiento debe ser un valor válido del catálogo CAT-009.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
        ]);
    }
}
