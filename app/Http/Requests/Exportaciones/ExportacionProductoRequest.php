<?php

namespace App\Http\Requests\Exportaciones;

use Illuminate\Foundation\Http\FormRequest;

class ExportacionProductoRequest extends FormRequest
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
            'codigo' => ['nullable', 'string', 'max:50'],
            'nombre_es' => ['required', 'string', 'max:255'],
            'nombre_en' => ['required', 'string', 'max:255'],
            'unidad' => ['nullable', 'string', 'max:255'],
            'unidades_por_caja' => ['required', 'integer', 'min:1'],
            'gramos_por_unidad' => ['required', 'numeric', 'min:0'],
            'onzas_por_unidad' => ['required', 'numeric', 'min:0'],
            // Precio BASE de referencia: el precio real por cliente vive en
            // exportacion_cliente_productos y manda sobre este.
            'precio_caja' => ['nullable', 'numeric', 'min:0'],
            'peso_neto_caja_kg' => ['required', 'numeric', 'min:0'],
            'peso_bruto_caja_kg' => ['required', 'numeric', 'min:0'],
            'peso_neto_caja_lb' => ['required', 'numeric', 'min:0'],
            'peso_bruto_caja_lb' => ['required', 'numeric', 'min:0'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre_es' => 'nombre en español',
            'nombre_en' => 'nombre en inglés',
            'unidades_por_caja' => 'unidades por caja',
            'gramos_por_unidad' => 'gramos por unidad',
            'onzas_por_unidad' => 'onzas por unidad',
            'precio_caja' => 'precio base por caja',
            'peso_neto_caja_kg' => 'peso neto por caja (kg)',
            'peso_bruto_caja_kg' => 'peso bruto por caja (kg)',
            'peso_neto_caja_lb' => 'peso neto por caja (lb)',
            'peso_bruto_caja_lb' => 'peso bruto por caja (lb)',
        ];
    }
}
