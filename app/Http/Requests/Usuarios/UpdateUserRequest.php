<?php

namespace App\Http\Requests\Usuarios;

use App\Enums\RolSistema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // UserPolicy::update se valida en el controlador.
    }

    public function rules(): array
    {
        $userId = $this->route('usuario')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'rol' => ['required', Rule::in(RolSistema::nombres())],
            'activo' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['activo' => $this->boolean('activo')]);
    }
}
