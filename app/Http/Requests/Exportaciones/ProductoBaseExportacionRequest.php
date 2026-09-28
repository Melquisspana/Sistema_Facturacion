<?php

namespace App\Http\Requests\Exportaciones;

use App\Enums\CategoriaProductoExportacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Producto base de exportación. En el alta viene junto con su primera
 * presentación (`con_presentacion`); al editarlo, solo nombres y categoría.
 */
class ProductoBaseExportacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización la cubre el middleware de permiso de la ruta
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = $this->route('base');

        $reglas = [
            'nombre_es' => [
                'required', 'string', 'max:255',
                Rule::unique('exportacion_productos_base', 'nombre_es')->ignore($base?->id),
            ],
            'nombre_en' => ['required', 'string', 'max:255'],
            'categoria' => ['nullable', Rule::enum(CategoriaProductoExportacion::class)],
        ];

        return $base === null
            ? $reglas + PresentacionExportacionRequest::reglasPresentacion()
            : $reglas;
    }

    public function attributes(): array
    {
        return [
            'nombre_es' => 'nombre en español',
            'nombre_en' => 'nombre en inglés',
        ] + PresentacionExportacionRequest::atributosPresentacion();
    }

    public function messages(): array
    {
        return [
            'nombre_es.unique' => 'Ya existe un producto con ese nombre. Agregale una presentación en vez de crearlo de nuevo.',
        ];
    }
}
