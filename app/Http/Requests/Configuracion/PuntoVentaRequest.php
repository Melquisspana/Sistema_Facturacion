<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PuntoVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $puntoVentaId = $this->route('puntoVenta')?->id;

        return [
            'establecimiento_id' => ['required', 'exists:establecimientos,id'],
            'codigo' => [
                'required', 'string', 'max:4',
                Rule::unique('puntos_venta', 'codigo')
                    ->where(fn ($q) => $q->where('establecimiento_id', $this->establecimiento_id))
                    ->whereNull('deleted_at')
                    ->ignore($puntoVentaId),
            ],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
        ]);
    }
}
