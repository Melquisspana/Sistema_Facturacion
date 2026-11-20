<?php

namespace App\Http\Requests\Ppq;

use Illuminate\Foundation\Http\FormRequest;

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
        // Solo lo que muestra el formulario. El estado lo mueve el sistema (borrador →
        // presentado → pagado) y el cliente es el del pronto pago: aceptarlos de la petición
        // dejaba reabrir un lote presentado o pasarlo a otro cliente con un pedido a mano.
        return [
            'referencia' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
