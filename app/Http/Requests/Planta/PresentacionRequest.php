<?php

namespace App\Http\Requests\Planta;

use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaProductoBase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PresentacionRequest extends FormRequest
{
    /** Unidades admitidas para el contenido de una presentación. */
    public const UNIDADES_CONTENIDO = ['g', 'lb', 'unidad'];

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
            'planta_producto_base_id' => ['required', 'integer', Rule::exists('planta_productos_base', 'id')->whereNull('deleted_at')],
            'codigo' => [
                'required', 'string', 'max:30',
                Rule::unique('planta_presentaciones', 'codigo')->ignore($this->route('presentacion')),
            ],
            'nombre' => [
                'required', 'string', 'max:150',
                // El nombre solo tiene que ser único DENTRO de su producto base:
                // dos dulces distintos pueden tener ambos un «85 g».
                Rule::unique('planta_presentaciones', 'nombre')
                    ->where('planta_producto_base_id', $this->input('planta_producto_base_id'))
                    ->ignore($this->route('presentacion')),
            ],
            'contenido' => ['nullable', 'numeric', 'gt:0'],
            'unidad_contenido' => ['nullable', Rule::in(self::UNIDADES_CONTENIDO)],
            'unidades_por_bulto' => ['nullable', 'integer', 'min:1'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $base = PlantaProductoBase::find($this->input('planta_producto_base_id'));

                if (! $base) {
                    return; // ya lo cubre la regla `exists`
                }

                // Se admite conservar el producto base histórico de una
                // presentación ya guardada aunque se haya desactivado; lo que no
                // se admite es colgar una presentación de un producto inactivo.
                $presentacion = $this->route('presentacion');
                $esElMismo = $presentacion instanceof PlantaPresentacion
                    && $presentacion->planta_producto_base_id === $base->id;

                if (! $base->activo && ! $esElMismo) {
                    $validator->errors()->add(
                        'planta_producto_base_id',
                        'El producto base seleccionado está inactivo.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'planta_producto_base_id' => 'producto base',
            'codigo' => 'código',
            'unidad_contenido' => 'unidad de contenido',
            'unidades_por_bulto' => 'unidades por bulto',
        ];
    }
}
