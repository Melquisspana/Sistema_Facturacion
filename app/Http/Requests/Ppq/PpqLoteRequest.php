<?php

namespace App\Http\Requests\Ppq;

use App\Enums\EstadoPpq;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class PpqLoteRequest extends FormRequest
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
            'referencia' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date'],
            // El estado se pone solo (borrador → presentado → pagado); se acepta si llega.
            'estado' => ['sometimes', new Enum(EstadoPpq::class)],
            'cliente_id' => ['nullable', Rule::exists('clientes', 'id')],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
