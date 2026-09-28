<?php

namespace App\Http\Requests\Exportaciones;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización de acceso la cubre el middleware de rol
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'exportacion_cliente_id' => ['required', 'integer', Rule::exists('exportacion_clientes', 'id')],
            'cliente_nombre' => ['required', 'string', 'max:255'],
            'cliente_direccion' => ['nullable', 'string', 'max:255'],
            'exportador_nombre' => ['required', 'string', 'max:255'],
            'exportador_direccion' => ['nullable', 'string', 'max:255'],
            'fecha' => ['required', 'date'],
            'factura' => ['nullable', 'string', 'max:100'],
            'fda_reg_number' => ['nullable', 'string', 'max:50'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            // id presente = item existente (conserva su snapshot); ausente = item nuevo.
            'items.*.id' => ['nullable', 'integer', Rule::exists('exportacion_items', 'id')],
            'items.*.exportacion_producto_id' => ['required_without:items.*.id', 'nullable', 'integer', Rule::exists('exportacion_productos', 'id')],
            'items.*.cantidad_cajas' => ['required', 'integer', 'min:1'],
            // Precio de la línea: vacío = el vigente del cliente (o el base). Si se
            // cambia, al finalizar la lista pasa a ser el precio del cliente.
            'items.*.precio_caja' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'exportacion_cliente_id' => 'cliente de exportación',
            'cliente_nombre' => 'cliente',
            'exportador_nombre' => 'exportador',
            'items' => 'productos',
            'items.*.exportacion_producto_id' => 'producto',
            'items.*.cantidad_cajas' => 'cantidad de cajas',
            'items.*.precio_caja' => 'precio por caja',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Agregá al menos un producto a la lista de empaque.',
            'items.min' => 'Agregá al menos un producto a la lista de empaque.',
        ];
    }
}
