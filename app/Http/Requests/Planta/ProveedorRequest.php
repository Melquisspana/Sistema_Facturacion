<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

class ProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización de acceso la cubre el middleware de la ruta
    }

    /**
     * Longitudes alineadas con la migración. NIT y NRC van SIN unique a
     * propósito: son texto libre, llegan vacíos con frecuencia y se corrigen
     * después; un unique convertiría un dato auxiliar en un bloqueo para
     * registrar mercancía que ya está en la bodega.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'nombre_comercial' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'contacto' => ['nullable', 'string', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:20'],
            'nrc' => ['nullable', 'string', 'max:20'],
            'observaciones' => ['nullable', 'string'],
            'activo' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre_comercial' => 'nombre comercial',
            'telefono' => 'teléfono',
            'correo' => 'correo electrónico',
            'direccion' => 'dirección',
        ];
    }
}
