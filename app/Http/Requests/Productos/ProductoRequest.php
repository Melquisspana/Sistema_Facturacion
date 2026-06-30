<?php

namespace App\Http\Requests\Productos;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso por rol lo decide ProductoPolicy en el controlador.
        return true;
    }

    public function rules(): array
    {
        $productoId = $this->route('producto')?->id;

        return [
            'codigo' => [
                'required', 'string', 'max:50',
                Rule::unique('productos', 'codigo')->whereNull('deleted_at')->ignore($productoId),
            ],
            // Opcional, pero único cuando viene lleno (nullable corta las reglas si va vacío).
            'codigo_barra' => [
                'nullable', 'string', 'min:6', 'max:50',
                Rule::unique('productos', 'codigo_barra')->whereNull('deleted_at')->ignore($productoId),
            ],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'tipo_producto' => ['required', Rule::in(array_column(TipoProducto::cases(), 'value'))],
            'unidad_medida_id' => ['required', 'exists:unidades_medida,id'],
            'precio_unitario' => ['required', 'numeric', 'min:0'],
            'tipo_impuesto' => ['required', Rule::in(array_column(TipoImpuesto::cases(), 'value'))],
            'maneja_inventario' => ['boolean'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.unique' => 'Ya existe un producto con ese código interno.',
            'codigo_barra.unique' => 'Ya existe un producto con ese código de barra.',
            'tipo_producto.in' => 'El tipo de producto debe ser un valor válido del catálogo.',
            'tipo_impuesto.in' => 'El tipo de impuesto debe ser gravado, exento o no sujeto.',
            'unidad_medida_id.exists' => 'La unidad de medida seleccionada no es válida.',
            'precio_unitario.min' => 'El precio no puede ser negativo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $limpio = [];
        foreach ($this->all() as $clave => $valor) {
            if (is_string($valor)) {
                $valor = trim($valor);
                if ($valor === '') {
                    $valor = null;
                }
            }
            $limpio[$clave] = $valor;
        }

        $limpio['activo'] = $this->boolean('activo');
        $limpio['maneja_inventario'] = $this->boolean('maneja_inventario');

        $this->merge($limpio);
    }
}
