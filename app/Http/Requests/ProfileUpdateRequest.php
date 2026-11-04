<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Quien tiene ficha en Personal no cambia su nombre acá: es el de la ficha, y se
     * cambia allá. Si lo manda distinto, se rechaza; si no lo manda, no se toca.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->user()->tieneFichaEnPersonal()
                ? ['sometimes', 'string', Rule::in([$this->user()->name])]
                : ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.in' => 'Tu nombre se cambia en tu ficha de Personal.'];
    }
}
