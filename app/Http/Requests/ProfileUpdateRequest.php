<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\LimiteContrasena;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
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
            'current_password' => $this->cambiaElCorreo()
                ? ['required', 'string', 'current_password']
                : ['exclude'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.in' => 'Tu nombre se cambia en tu ficha de Personal.',
            'current_password.required' => 'Para cambiar tu correo, escribí tu contraseña actual.',
            'current_password.string' => 'Escribí tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta. El correo no se cambió.',
        ];
    }

    /**
     * Cambiar el correo pide la contraseña actual: si no tuviera límite, sería otra puerta
     * para probar contraseñas con una sesión ajena. Comparte el contador con confirmarla y
     * cambiarla, y el intento se cuenta antes de comprobarla.
     */
    protected function prepareForValidation(): void
    {
        if ($this->cambiaElCorreo()) {
            LimiteContrasena::contar($this->claveIntentos(), 'current_password');
        }
    }

    /** Solo cuenta la contraseña equivocada: si falló otra cosa, el intento se devuelve. */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->cambiaElCorreo() && ! $validator->errors()->has('current_password')) {
            LimiteContrasena::devolver($this->claveIntentos());
        }

        parent::failedValidation($validator);
    }

    protected function passedValidation(): void
    {
        if ($this->cambiaElCorreo()) {
            LimiteContrasena::limpiar($this->claveIntentos());
        }
    }

    private function cambiaElCorreo(): bool
    {
        return $this->input('email') !== $this->user()->email;
    }

    private function claveIntentos(): string
    {
        return LimiteContrasena::clave($this->user());
    }
}
