<?php

namespace App\Http\Requests\Asistencia;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un lector biométrico.
 *
 * NO acepta `token_hash` ni ningún campo de token: el secreto no se escribe a
 * mano nunca. Se genera al dar de alta y se renueva con la rotación, que es una
 * pantalla aparte con su propia confirmación. Si esta clase aceptara un token,
 * existiría un camino por el que alguien podría FIJAR el que quisiera.
 *
 * `activo` va aparte por la misma razón que en el empleado: desactivar un lector
 * lo deja sin autenticar y es un acto que merece su propio botón, no un checkbox.
 */
class DispositivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // El código viaja en la cabecera X-Dispositivo del firmware: se
            // restringe a lo que un identificador de máquina puede ser, para que
            // no acabe con espacios o acentos que compliquen quemarlo en el ESP32.
            'codigo' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9\-_]*$/',
                Rule::unique('asistencia_dispositivos', 'codigo')->ignore($this->route('dispositivo')),
            ],
            'nombre' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['codigo' => 'código', 'nombre' => 'nombre'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'codigo.regex' => 'El código solo admite minúsculas, números, guiones y guiones bajos, y debe empezar por letra o número.',
            'codigo.unique' => 'Ya hay un lector con ese código.',
        ];
    }
}
