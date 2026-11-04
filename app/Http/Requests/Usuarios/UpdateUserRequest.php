<?php

namespace App\Http\Requests\Usuarios;

use App\Enums\RolSistema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rol, correo y nombre de una cuenta, desde «Acceso y módulos» de la ficha o desde la página
 * de la cuenta en Personal › Accesos. Activar o desactivar va aparte (toggleActivo). El nombre
 * solo cuenta si la cuenta no tiene ficha: el controlador lo exige en ese caso.
 */
class UpdateUserRequest extends FormRequest
{
    /** Los errores van a su propio bloque: la ficha tiene otros formularios con los mismos campos. */
    protected $errorBag = 'acceso';

    public function authorize(): bool
    {
        return true; // UserPolicy::update se valida en el controlador.
    }

    public function rules(): array
    {
        $userId = $this->route('usuario')?->id;

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'rol' => ['required', Rule::in(RolSistema::nombres())],
        ];
    }
}
