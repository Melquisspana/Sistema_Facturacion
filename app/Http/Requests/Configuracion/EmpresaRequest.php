<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\AmbienteHacienda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso de administrador ya lo impone el middleware de la ruta.
        return true;
    }

    public function rules(): array
    {
        return [
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:20', 'regex:/^[0-9-]{9,17}$/'],
            'nrc' => ['nullable', 'string', 'max:20'],
            'actividad_economica_id' => ['nullable', 'exists:actividades_economicas,id'],
            'pais_id' => ['nullable', 'exists:paises,id'],
            'departamento_id' => ['nullable', 'exists:departamentos,id'],
            // El municipio debe existir Y pertenecer al departamento seleccionado.
            'municipio_id' => [
                'nullable',
                Rule::exists('municipios', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ],
            // Distrito (división 2024) del emisor. Nullable + debe pertenecer al departamento.
            'distrito_id' => [
                'nullable',
                Rule::exists('distritos', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'ambiente' => ['required', Rule::in(array_column(AmbienteHacienda::cases(), 'value'))],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'municipio_id.exists' => 'El municipio seleccionado no pertenece al departamento elegido.',
            'ambiente.in' => 'El ambiente debe ser un valor válido del catálogo (pruebas o producción).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
        ]);
    }
}
