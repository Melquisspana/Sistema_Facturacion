<?php

namespace App\Http\Requests\Rutas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una salida de ruta.
 *
 * NO valida ni acepta `estado`: el estado se mueve con las acciones con nombre
 * propio (iniciar / finalizar / cancelar), nunca como un campo del formulario.
 * Tampoco acepta `fecha_fin_real`: esa la escribe el acto de finalizar.
 */
class SalidaRutaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Solo rutas activas: planificar sobre una ruta desactivada sería
            // revivirla por la puerta de atrás.
            'ruta_id' => ['required', Rule::exists('rutas', 'id')->where('activa', true)],
            'fecha_inicio' => ['required', 'date'],
            // Una salida puede durar varios días; lo único inaceptable es regresar
            // antes de salir.
            'fecha_fin_estimada' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observaciones' => ['nullable', 'string', 'max:1000'],

            // Participantes: al menos uno, y solo usuarios activos. No se exige
            // ningún rol todavía (no existe rol vendedor).
            'vendedores' => ['required', 'array', 'min:1'],
            'vendedores.*' => [Rule::exists('users', 'id')->where('activo', true)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'ruta_id' => 'ruta',
            'fecha_inicio' => 'fecha de inicio',
            'fecha_fin_estimada' => 'fecha estimada de regreso',
            'vendedores' => 'vendedores',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ruta_id.exists' => 'Esa ruta no existe o está desactivada.',
            'vendedores.required' => 'Elegí al menos una persona para la salida.',
            'vendedores.*.exists' => 'Alguno de los usuarios elegidos no existe o está inactivo.',
            'fecha_fin_estimada.after_or_equal' => 'El regreso estimado no puede ser anterior a la salida.',
        ];
    }
}
