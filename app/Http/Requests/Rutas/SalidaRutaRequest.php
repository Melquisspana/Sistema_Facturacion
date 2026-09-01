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

            // Participantes: al menos uno, del catálogo de personal de campo y activos.
            // Apuntan a `rutas_personal` y no a `users` porque los vendedores no tienen
            // login ni deben tenerlo.
            'personal' => ['required', 'array', 'min:1'],
            'personal.*' => [Rule::exists('rutas_personal', 'id')->where('activo', true)],

            // Quién queda a cargo del viaje. OPCIONAL: una salida de una sola persona no
            // necesita que nadie responda por el grupo. Que esté entre los participantes lo
            // comprueba el servicio, que es quien puede verlo contra la lista final.
            'responsable_id' => ['nullable', Rule::exists('rutas_personal', 'id')->where('activo', true)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'ruta_id' => 'ruta',
            'fecha_inicio' => 'fecha de inicio',
            'fecha_fin_estimada' => 'fecha estimada de regreso',
            'personal' => 'participantes',
            'responsable_id' => 'responsable',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ruta_id.exists' => 'Esa ruta no existe o está desactivada.',
            'personal.required' => 'Elegí al menos una persona para la salida.',
            'personal.*.exists' => 'Alguna de las personas elegidas no existe o está inactiva.',
            'responsable_id.exists' => 'Esa persona no existe o está inactiva.',
            'fecha_fin_estimada.after_or_equal' => 'El regreso estimado no puede ser anterior a la salida.',
        ];
    }
}
