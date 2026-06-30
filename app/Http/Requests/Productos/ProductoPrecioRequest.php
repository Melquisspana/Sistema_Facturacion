<?php

namespace App\Http\Requests\Productos;

use App\Models\ClienteSucursal;
use App\Models\ProductoPrecioCliente;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de un precio especial de producto por cliente/sucursal.
 *
 * No se permite duplicar un precio ACTIVO para el mismo producto + cliente +
 * sucursal. La sucursal (si se indica) debe pertenecer al cliente.
 */
class ProductoPrecioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el rol lo decide el controlador (ProductoPolicy::update)
    }

    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'cliente_sucursal_id' => ['nullable', 'integer', 'exists:cliente_sucursales,id'],
            'precio' => ['required', 'numeric', 'min:0'],
            'activo' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['activo' => $this->boolean('activo')]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $clienteId = $this->input('cliente_id');
            $sucursalId = $this->input('cliente_sucursal_id');

            // La sucursal debe pertenecer al cliente.
            if ($sucursalId) {
                $perteneceAlCliente = ClienteSucursal::where('id', $sucursalId)->where('cliente_id', $clienteId)->exists();
                if (! $perteneceAlCliente) {
                    $validator->errors()->add('cliente_sucursal_id', 'La sucursal no pertenece al cliente seleccionado.');
                }
            }

            // No duplicar un precio activo para el mismo producto + cliente + sucursal.
            if ($this->boolean('activo')) {
                $producto = $this->route('producto');
                $duplicado = ProductoPrecioCliente::query()
                    ->where('producto_id', $producto->id)
                    ->where('cliente_id', $clienteId)
                    ->where('cliente_sucursal_id', $sucursalId)
                    ->where('activo', true)
                    ->exists();

                if ($duplicado) {
                    $validator->errors()->add('precio', 'Ya existe un precio activo para ese cliente/sucursal.');
                }
            }
        });
    }
}
