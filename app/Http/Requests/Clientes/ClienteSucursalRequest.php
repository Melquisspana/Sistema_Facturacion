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
 * `municipio_id` es el catálogo CAT-013 y va aparte de la cadena 2024: es el dato
 * que el serializador toma de la sala para `receptor.direccion.municipio`. Es
 * OPCIONAL (el catálogo sembrado no cubre todos los departamentos y hay salas
 * viejas sin él), pero si viene debe pertenecer al departamento elegido.
 *
 * requiere_orden_compra es ternario: vacío = heredar del cliente (null),
 * "1" = sí, "0" = no.
 */
class ClienteSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gestionar salas/sucursales = gestionar el cliente (permiso clientes.gestionar,
        // solo admin). Autoriza antes de validar; el controlador vuelve a autorizar.
        return (bool) $this->user()?->can('clientes.gestionar');
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
            // CAT-013. Opcional, pero si se envía debe ser del mismo departamento.
            'municipio_id' => [
                'nullable',
                Rule::exists('municipios', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ],
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

        // "— Sin municipio —" llega como cadena vacía: normalizar a null para que
        // la regla `nullable` aplique y no se dispare `exists` contra ''.
        if ($this->input('municipio_id') === '') {
            $this->merge(['municipio_id' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la sucursal/sala es obligatorio.',
            'departamento_id.required' => 'El departamento es obligatorio.',
            'distrito_id.required' => 'El distrito es obligatorio.',
            'distrito_id.exists' => 'El distrito seleccionado no pertenece al departamento elegido.',
            'municipio_id.exists' => 'El municipio seleccionado no pertenece al departamento elegido.',
        ];
    }
}
