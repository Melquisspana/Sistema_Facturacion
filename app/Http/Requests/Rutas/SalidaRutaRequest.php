<?php

namespace App\Http\Requests\Rutas;

use App\Support\HoraNegocio;
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

    /**
     * «Armar salida» manda el día como «hoy» u «otro»: con «hoy» se ignora lo que haya
     * quedado en el campo de fecha y vale el día de negocio.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('dia') === 'hoy') {
            $this->merge(['fecha_inicio' => HoraNegocio::fechaHoy()]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Al armar una salida, el día es hoy o uno que viene, nunca uno pasado. La edición
        // no se toca: una salida en curso de varios días ya empezó antes de hoy.
        $alArmar = $this->route('salida') === null;

        return [
            // Solo rutas activas: planificar sobre una ruta desactivada sería
            // revivirla por la puerta de atrás.
            'ruta_id' => ['required', Rule::exists('rutas', 'id')->where('activa', true)],
            'dia' => ['nullable', Rule::in(['hoy', 'otro'])],
            // Sin fecha = hoy: «Salir a esta ruta» es salir ahora.
            'fecha_inicio' => array_merge(
                [$this->input('dia') === 'otro' ? 'required' : 'nullable', 'date'],
                $alArmar ? ['after_or_equal:'.HoraNegocio::fechaHoy()] : [],
            ),
            // Una salida puede durar varios días; lo único inaceptable es regresar
            // antes de salir.
            'fecha_fin_estimada' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observaciones' => ['nullable', 'string', 'max:1000'],

            // Participantes: al menos uno, del catálogo de personal de campo y activos.
            // Apuntan a `rutas_personal` y no a `users` porque los vendedores no tienen
            // login ni deben tenerlo.
            'personal' => ['required', 'array', 'min:1'],
            'personal.*' => [Rule::exists('rutas_personal', 'id')->where('activo', true)],

            // Quién la encabeza (opcional): tiene que ir en la salida. Volvió el 03/11/2026
            // con «Armar salida»; ParticipantesSalida lo vuelve a comprobar al guardar.
            'responsable_id' => ['nullable', 'integer', Rule::in(array_map('intval', (array) $this->input('personal', [])))],
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
            'responsable_id' => 'quién la encabeza',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ruta_id.exists' => 'Esa ruta no existe o está desactivada.',
            'personal.required' => 'Elegí al menos una persona para la salida.',
            'personal.*.exists' => 'Alguna de las personas elegidas no existe o está inactiva.',
            'fecha_fin_estimada.after_or_equal' => 'El regreso estimado no puede ser anterior a la salida.',
            'fecha_inicio.required' => 'Elegí el día de la salida.',
            'fecha_inicio.after_or_equal' => 'La salida tiene que ser para hoy o para un día que viene.',
            'responsable_id.in' => 'Quien la encabeza tiene que ir en la salida. Marcalo en «¿Quiénes van?» o elegí a otro.',
        ];
    }
}
