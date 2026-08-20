<?php

namespace App\Http\Requests\Configuracion;

use App\Support\Ubicacion\CoherenciaUbicacion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos del emisor. NO incluye el ambiente fiscal: la columna `empresas.ambiente`
 * sigue existiendo en la tabla pero NINGÚN consumidor fiscal la lee — el ambiente que
 * viaja en el JSON del MH sale siempre de `config('dte.ambiente')` (DTE_AMBIENTE).
 * Mientras estuvo en este formulario, un administrador podía elegir "Producción" y
 * creer que había cambiado el ambiente de emisión sin que cambiara nada en absoluto.
 * Al no declararse aquí, `ambiente` nunca llega a validated() y el controlador no
 * puede escribirlo, mande lo que mande el formulario.
 */
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
            // `ambiente` está ausente a propósito (ver la nota de clase).
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'municipio_id.exists' => 'El municipio seleccionado no pertenece al departamento elegido.',
            'distrito_id.exists' => 'El distrito seleccionado no pertenece al departamento elegido.',
        ];
    }

    /**
     * Coherencia del trío departamento → municipio 2024 → distrito del EMISOR. El JSON del
     * MH lleva la dirección del emisor en CCF, Factura y FEX: un par municipio/distrito
     * incompatible acá rompe todos los documentos, no solo uno.
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
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
        ]);
    }
}
