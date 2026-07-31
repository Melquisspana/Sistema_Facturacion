<?php

namespace App\Http\Requests\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de FORMA de un borrador de cambio de disponibilidad.
 *
 * Las reglas de DOMINIO viven en PlantaCambioDisponibilidadService y se aplican
 * SIEMPRE, también a una petición construida a mano: que la ubicación admita
 * operación manual, que el lote sea del insumo, que la transición esté permitida
 * y —la que importa— que HAYA saldo retenido suficiente en ese bucket exacto.
 *
 * `estado_origen` NO se valida porque NO se acepta: siempre es `retenido` y lo
 * fija el servidor. Si llega en la petición, se descarta.
 */
class CambioDisponibilidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.calidad.gestionar lo aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planta_insumo_id' => ['required', 'integer', Rule::exists('planta_insumos', 'id')->whereNull('deleted_at')],
            'planta_lote_id' => ['required', 'integer', Rule::exists('planta_lotes', 'id')],
            'planta_ubicacion_id' => ['required', 'integer', Rule::exists('planta_ubicaciones', 'id')->whereNull('deleted_at')],
            // `retenido` queda fuera a propósito: es el origen, no un destino.
            'estado_destino' => ['required', Rule::in([
                EstadoDisponibilidad::Disponible->value,
                EstadoDisponibilidad::Rechazado->value,
            ])],
            // > 0 y no >= 0: un cambio de cero no cambia nada.
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'fecha' => ['required', 'date'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
            'responsable_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'responsable_nombre' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * El selector envía el bucket entero en un solo campo (`bucket`), porque
     * capturar insumo, lote y ubicación por separado permitiría pedir una
     * combinación sin saldo retenido. Aquí se descompone en las tres columnas.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('responsable_user_id') === '') {
            $this->merge(['responsable_user_id' => null]);
        }

        $bucket = (string) $this->input('bucket', '');

        if ($bucket === '') {
            return;
        }

        $partes = explode('|', $bucket);

        if (count($partes) !== 3) {
            return;
        }

        $this->merge([
            'planta_insumo_id' => $partes[0],
            'planta_lote_id' => $partes[1],
            'planta_ubicacion_id' => $partes[2],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'planta_insumo_id' => 'insumo',
            'planta_lote_id' => 'lote',
            'planta_ubicacion_id' => 'ubicación',
            'estado_destino' => 'destino',
            'responsable_user_id' => 'responsable',
            'responsable_nombre' => 'nombre del responsable',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Indica por qué cambia la disponibilidad de este saldo.',
            'motivo.min' => 'El motivo debe explicar la decisión: al menos 10 caracteres.',
            'planta_insumo_id.required' => 'Selecciona un saldo retenido.',
        ];
    }
}
