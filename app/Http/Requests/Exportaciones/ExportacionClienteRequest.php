<?php

namespace App\Http\Requests\Exportaciones;

use Illuminate\Foundation\Http\FormRequest;

class ExportacionClienteRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'fda_reg_number' => ['nullable', 'string', 'max:50'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ];
    }
}
