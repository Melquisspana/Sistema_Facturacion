<?php

namespace App\Http\Requests\Rutas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una zona: solo su nombre y si está activa. La autorización la resuelve el
 * middleware `permission:rutas.gestionar` de la ruta, no este request. Los días aproximados
 * (`frecuencia_objetivo_dias`) ya no se cargan (10/11/2026): no se validan ni se guardan.
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
            'nombre' => 'nombre de la zona',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya existe una zona con ese nombre.',
        ];
    }
}
