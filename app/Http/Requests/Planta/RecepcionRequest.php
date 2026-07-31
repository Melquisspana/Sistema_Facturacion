<?php

namespace App\Http\Requests\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de FORMA de un borrador de recepción (alta y edición).
 *
 * Las reglas de DOMINIO viven en PlantaRecepcionService y se aplican SIEMPRE,
 * también a una petición construida a mano: que la ubicación admita operación
 * manual, que los insumos sigan activos, que el lote reutilizado sea del mismo
 * insumo, y que recibir como RETENIDO exija `planta.calidad.gestionar`. Este
 * formulario es una comodidad, no una barrera.
 *
 * `cantidad_base` y `unidad_base` NO se validan porque NO se aceptan: son
 * valores derivados que el servidor recalcula. Si llegan en la petición, se
 * descartan sin más.
 */
class RecepcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso lo cubre el middleware de la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'planta_proveedor_id' => ['nullable', 'integer', Rule::exists('planta_proveedores', 'id')->whereNull('deleted_at')],
            'planta_ubicacion_id' => ['required', 'integer', Rule::exists('planta_ubicaciones', 'id')->whereNull('deleted_at')],
            'documento_referencia' => ['nullable', 'string', 'max:60'],
            'responsable_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'responsable_nombre' => ['nullable', 'string', 'max:120'],
            'observaciones' => ['nullable', 'string', 'max:2000'],

            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.id' => ['nullable', 'integer'],
            'detalles.*.planta_insumo_id' => ['required', 'integer', Rule::exists('planta_insumos', 'id')->whereNull('deleted_at')],
            'detalles.*.planta_lote_id' => ['nullable', 'integer', Rule::exists('planta_lotes', 'id')],
            // > 0 y no solo >= 0: una línea de cero no registra ninguna entrada.
            'detalles.*.cantidad_recibida' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'detalles.*.unidad_recibida' => ['required', 'string', 'max:30'],
            'detalles.*.contenido_por_unidad' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'detalles.*.factor_conversion' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            // `rechazado` queda fuera a propósito: no es una forma de recibir.
            'detalles.*.estado_destino' => ['required', Rule::in([
                EstadoDisponibilidad::Disponible->value,
                EstadoDisponibilidad::Retenido->value,
            ])],
            'detalles.*.lote_codigo_proveedor' => ['nullable', 'string', 'max:60'],
            'detalles.*.fecha_elaboracion' => ['nullable', 'date'],
            'detalles.*.fecha_vencimiento' => ['nullable', 'date', 'after_or_equal:detalles.*.fecha_elaboracion'],
            'detalles.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Convierte los selects vacíos en NULL y descarta las líneas en blanco. */
    protected function prepareForValidation(): void
    {
        foreach (['planta_proveedor_id', 'responsable_user_id'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }

        $detalles = $this->input('detalles');

        if (! is_array($detalles)) {
            return;
        }

        $limpios = [];

        foreach ($detalles as $linea) {
            if (! is_array($linea) || blank($linea['planta_insumo_id'] ?? null)) {
                // Fila del formulario que el usuario dejó vacía: no es un error,
                // simplemente no existe.
                continue;
            }

            foreach (['planta_lote_id', 'fecha_elaboracion', 'fecha_vencimiento'] as $campo) {
                if (($linea[$campo] ?? null) === '') {
                    $linea[$campo] = null;
                }
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
            'planta_proveedor_id' => 'proveedor',
            'planta_ubicacion_id' => 'ubicación',
            'documento_referencia' => 'documento de referencia',
            'responsable_user_id' => 'responsable',
            'responsable_nombre' => 'nombre del responsable',
            'detalles' => 'líneas',
            'detalles.*.planta_insumo_id' => 'insumo',
            'detalles.*.planta_lote_id' => 'lote',
            'detalles.*.cantidad_recibida' => 'cantidad recibida',
            'detalles.*.unidad_recibida' => 'unidad recibida',
            'detalles.*.contenido_por_unidad' => 'contenido por unidad',
            'detalles.*.factor_conversion' => 'factor de conversión',
            'detalles.*.estado_destino' => 'destino',
            'detalles.*.lote_codigo_proveedor' => 'lote del proveedor',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'detalles.required' => 'La recepción necesita al menos una línea.',
            'detalles.min' => 'La recepción necesita al menos una línea.',
        ];
    }
}
