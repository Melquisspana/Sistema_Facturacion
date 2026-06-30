<?php

namespace App\Http\Requests\Clientes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de sucursales / salas de un cliente (crear y editar).
 *
 * Ubicación administrativa (división 2024): departamento → municipio → distrito.
 * El distrito es OBLIGATORIO (requisito legal) y debe pertenecer al departamento.
 *
 * requiere_orden_compra es ternario: vacío = heredar del cliente (null),
 * "1" = sí, "0" = no.
 */
class ClienteSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización por rol la decide el controlador (ClientePolicy)
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:50'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'departamento_id' => ['required', 'exists:departamentos,id'],
            // El distrito debe existir Y pertenecer al departamento seleccionado.
            'distrito_id' => [
                'required',
                Rule::exists('distritos', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ],
            'municipio_id' => ['nullable', 'exists:municipios,id'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'requiere_orden_compra' => ['nullable', 'boolean'],
            'activo' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ternario de orden de compra: '' → null (hereda), '1' → true, '0' → false.
        $oc = $this->input('requiere_orden_compra');
        $this->merge([
            'requiere_orden_compra' => ($oc === '' || $oc === null) ? null : $this->boolean('requiere_orden_compra'),
            'activo' => $this->boolean('activo'),
        ]);
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la sucursal/sala es obligatorio.',
            'departamento_id.required' => 'El departamento es obligatorio.',
            'distrito_id.required' => 'El distrito es obligatorio.',
            'distrito_id.exists' => 'El distrito seleccionado no pertenece al departamento elegido.',
        ];
    }
}
