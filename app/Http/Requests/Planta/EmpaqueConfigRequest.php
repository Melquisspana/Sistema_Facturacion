<?php

namespace App\Http\Requests\Planta;

use App\Enums\Planta\MercadoPlanta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de FORMA de una configuración de empaque.
 *
 * Las reglas de DOMINIO —que la bolsa sea de tipo bolsa, la viñeta de tipo
 * viñeta, que los insumos estén activos, que no haya duplicados y que solo haya
 * una predeterminada por mercado— viven en EmpaqueConfigService y se aplican
 * SIEMPRE, aunque la petición se fuerce sin pasar por este formulario.
 */
class EmpaqueConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización de acceso la cubre el middleware de la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planta_presentacion_id' => ['required', 'integer', Rule::exists('planta_presentaciones', 'id')->whereNull('deleted_at')],
            'planta_insumo_bolsa_id' => ['required', 'integer', Rule::exists('planta_insumos', 'id')->whereNull('deleted_at')],
            'planta_insumo_vinieta_id' => ['nullable', 'integer', Rule::exists('planta_insumos', 'id')->whereNull('deleted_at')],
            'marca' => ['nullable', 'string', 'max:80'],
            'mercado' => ['required', Rule::enum(MercadoPlanta::class)],
            'referencia_cliente' => ['nullable', 'string', 'max:120'],
            'es_predeterminada' => ['required', 'boolean'],
            'activo' => ['required', 'boolean'],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ];
    }

    /** Normaliza la viñeta vacía del select a NULL antes de validar. */
    protected function prepareForValidation(): void
    {
        if ($this->input('planta_insumo_vinieta_id') === '') {
            $this->merge(['planta_insumo_vinieta_id' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'planta_presentacion_id' => 'presentación',
            'planta_insumo_bolsa_id' => 'bolsa',
            'planta_insumo_vinieta_id' => 'viñeta',
            'referencia_cliente' => 'referencia del cliente',
            'es_predeterminada' => 'predeterminada',
            'vigente_desde' => 'vigente desde',
            'vigente_hasta' => 'vigente hasta',
        ];
    }
}
