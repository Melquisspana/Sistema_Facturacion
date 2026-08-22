<?php

namespace App\Http\Requests\Asistencia;

use App\Services\Asistencia\SelectorRanura;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedir que el lector grabe una huella.
 *
 * ─────────────────── La ranura NO se escribe normalmente ───────────────────
 *
 * `ranura` es opcional y vive tras «opciones avanzadas». El flujo normal es 100 %
 * automático: el servidor elige la menor libre cruzando lo que sabe la base con lo
 * que reportó el sensor.
 *
 * El escape manual existe para UN caso: sensores que ya traen plantillas de antes
 * de que existiera este sistema, donde hace falta apuntar a un hueco concreto que
 * la persona conoce. No se salta ninguna protección —{@see SelectorRanura::motivoParaNoUsar()}
 * la comprueba contra las tres fuentes y el único de la base sigue mandando— pero
 * sí desactiva la elección automática, y por eso queda registrado en la orden con
 * `ranura_manual`.
 *
 * El rango se valida en el servicio y no acá: depende de la capacidad que reportó
 * ESE lector, que este request no conoce.
 */
class IniciarEnrolamientoRequest extends FormRequest
{
    /** La autorización la resuelve el middleware `permission:asistencia.gestionar`. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asistencia_dispositivo_id' => ['required', 'integer', 'exists:asistencia_dispositivos,id'],
            'ranura' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'asistencia_dispositivo_id' => 'lector',
            'ranura' => 'ranura',
        ];
    }

    /** `null` = automática, que es el camino normal. */
    public function ranuraManual(): ?int
    {
        $ranura = $this->input('ranura');

        return ($ranura === null || $ranura === '') ? null : (int) $ranura;
    }
}
