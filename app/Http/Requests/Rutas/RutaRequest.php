<?php

namespace App\Http\Requests\Rutas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una ruta. La autorización la resuelve el middleware
 * `permission:rutas.gestionar` de la ruta, no este request.
 */
class RutaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // En edición hay que ignorar la propia fila para que guardar sin cambiar
        // el nombre no choque contra su propio índice único.
        $rutaId = $this->route('ruta')?->id;

        return [
            'nombre' => [
                'required', 'string', 'max:120',
                Rule::unique('rutas', 'nombre')->ignore($rutaId),
            ],
            // Referencia operativa, no una regla: el sistema no agenda nada con esto.
            'frecuencia_objetivo_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'activa' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Un checkbox desmarcado no llega en el POST: sin esto, editar una ruta
        // desde el formulario nunca podría desactivarla.
        $this->merge(['activa' => $this->boolean('activa')]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre de la ruta',
            'frecuencia_objetivo_dias' => 'frecuencia objetivo',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya existe una ruta con ese nombre.',
        ];
    }
}
