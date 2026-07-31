<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de FORMA de un borrador de traslado.
 *
 * Las reglas de DOMINIO viven en PlantaTrasladoService y se aplican SIEMPRE,
 * también a una petición construida a mano: que las ubicaciones sean físicas y
 * operables, que el lote sea del insumo y esté activo, que exista la ubicación
 * de tránsito, y —la que importa— que HAYA saldo disponible suficiente en el
 * bucket exacto de origen.
 *
 * `planta_traslado_id` NO se acepta de ninguna forma: la quinta dimensión del
 * bucket la pone el servicio con el id del documento. Si el formulario pudiera
 * fijarla, se podría escribir saldo en el tránsito de OTRO viaje.
 */
class TrasladoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.traslados.crear lo aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'planta_ubicacion_origen_id' => ['required', 'integer', Rule::exists('planta_ubicaciones', 'id')->whereNull('deleted_at')],
            'planta_ubicacion_destino_id' => [
                'required', 'integer',
                Rule::exists('planta_ubicaciones', 'id')->whereNull('deleted_at'),
                // El servicio lo vuelve a comprobar; aquí es solo cortesía.
                'different:planta_ubicacion_origen_id',
            ],
            'responsable_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'responsable_nombre' => ['nullable', 'string', 'max:120'],
            'observaciones' => ['nullable', 'string', 'max:2000'],

            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.planta_insumo_id' => ['required', 'integer', Rule::exists('planta_insumos', 'id')->whereNull('deleted_at')],
            'detalles.*.planta_lote_id' => ['required', 'integer', Rule::exists('planta_lotes', 'id')],
            // > 0 y no >= 0: una línea de cero no traslada nada.
            'detalles.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'detalles.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * El selector envía insumo y lote juntos (`bucket`) porque son el saldo que
     * de verdad existe en el origen; capturarlos por separado permitiría pedir
     * una combinación sin saldo. Aquí se descompone en las dos columnas.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('responsable_user_id') === '') {
            $this->merge(['responsable_user_id' => null]);
        }

        $detalles = $this->input('detalles');

        if (! is_array($detalles)) {
            return;
        }

        $limpios = [];

        foreach ($detalles as $linea) {
            if (! is_array($linea)) {
                continue;
            }

            $bucket = (string) ($linea['bucket'] ?? '');

            if ($bucket !== '' && substr_count($bucket, '|') === 1) {
                [$linea['planta_insumo_id'], $linea['planta_lote_id']] = explode('|', $bucket);
            }

            // Fila del formulario que el usuario dejó vacía: no es un error.
            if (blank($linea['planta_insumo_id'] ?? null) || blank($linea['planta_lote_id'] ?? null)) {
                continue;
            }

            $limpios[] = $linea;
        }

        $this->merge(['detalles' => array_values($limpios)]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'planta_ubicacion_origen_id' => 'ubicación de origen',
            'planta_ubicacion_destino_id' => 'ubicación de destino',
            'responsable_user_id' => 'responsable',
            'responsable_nombre' => 'nombre del responsable',
            'detalles' => 'líneas',
            'detalles.*.planta_insumo_id' => 'insumo',
            'detalles.*.planta_lote_id' => 'lote',
            'detalles.*.cantidad' => 'cantidad',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'detalles.required' => 'El traslado necesita al menos una línea.',
            'detalles.min' => 'El traslado necesita al menos una línea.',
            'planta_ubicacion_destino_id.different' => 'El destino debe ser distinto del origen.',
        ];
    }
}
