<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización de acceso la cubre el middleware de la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => [
                'required', 'string', 'max:30',
                // La clave es `productoBase`: es el nombre del parámetro de ruta,
                // no el del modelo en snake_case.
                Rule::unique('planta_productos_base', 'codigo')->ignore($this->route('productoBase')),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'activo' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'codigo' => 'código',
            'descripcion' => 'descripción',
        ];
    }
}
