<?php

namespace App\Http\Requests\Exportaciones;

use App\Services\Exportaciones\CatalogoExportacion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una presentación del catálogo de exportación. Onzas y libras NO se validan ni
 * se aceptan: las calcula {@see CatalogoExportacion}.
 */
class PresentacionExportacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización la cubre el middleware de permiso de la ruta
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::reglasPresentacion();
    }

    public function attributes(): array
    {
        return self::atributosPresentacion();
    }

    /** @return array<string, mixed> */
    public static function reglasPresentacion(): array
    {
        return [
            'codigo' => ['nullable', 'string', 'max:50'],
            'unidad' => ['required', 'string', 'max:255'],
            'unidades_por_caja' => ['required', 'integer', 'min:1'],
            'gramos_por_unidad' => ['required', 'numeric', 'gt:0'],
            'peso_neto_caja_kg' => ['required', 'numeric', 'gt:0'],
            'peso_bruto_caja_kg' => ['nullable', 'numeric', 'gte:peso_neto_caja_kg'],
            // Precio BASE de referencia: el del cliente sale de su última lista.
            'precio_caja' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['nullable', 'boolean'],
            // A qué clientes se vende y a qué precio (solo al crear).
            'clientes' => ['nullable', 'array'],
            'clientes.*.activo' => ['nullable', 'boolean'],
            'clientes.*.precio' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public static function atributosPresentacion(): array
    {
        return [
            'unidad' => 'empaque',
            'unidades_por_caja' => 'unidades por caja',
            'gramos_por_unidad' => 'gramos por unidad',
            'peso_neto_caja_kg' => 'peso neto por caja',
            'peso_bruto_caja_kg' => 'peso bruto por caja',
            'precio_caja' => 'precio base por caja',
        ];
    }
}
