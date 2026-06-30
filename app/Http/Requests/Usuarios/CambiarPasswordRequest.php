<?php

namespace App\Http\Requests\Usuarios;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // UserPolicy::update se valida en el controlador.
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', PasswordRules::reglas()],
        ];
    }
}
