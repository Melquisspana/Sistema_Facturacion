<?php

namespace App\Http\Requests\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de FORMA de un borrador de ajuste.
 *
 * Las reglas de DOMINIO viven en PlantaAjusteService y se aplican SIEMPRE,
 * también a una petición construida a mano: que la ubicación sea operable, que
 * el lote sea del insumo, que haya saldo suficiente en los tipos que restan, que
 * la carga inicial caiga sobre un bucket sin historial, y que el lote esté
 * vencido de verdad en un ajuste por vencimiento.
 *
 * `cantidad_sistema` y `diferencia` NO se validan porque NO se aceptan: se
 * calculan en el servidor con el saldo leído bajo bloqueo al confirmar. Si
 * llegan en la petición, se descartan.
 *
 * El SIGNO tampoco se acepta: `cantidad` es una magnitud positiva y el tipo del
 * documento decide si suma o resta.
 */
class AjusteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.ajustes.crear lo aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $esConteo = $this->input('tipo') === TipoAjuste::CorreccionConteo->value;

        return [
            'tipo' => ['required', Rule::enum(TipoAjuste::class)],
            'fecha' => ['required', 'date'],
            // El motivo es obligatorio en TODOS los tipos, sin excepción.
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
            'responsable_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'responsable_nombre' => ['nullable', 'string', 'max:120'],
            'observaciones' => ['nullable', 'string', 'max:2000'],

            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.planta_insumo_id' => ['required', 'integer', Rule::exists('planta_insumos', 'id')->whereNull('deleted_at')],
            'detalles.*.planta_lote_id' => ['nullable', 'integer', Rule::exists('planta_lotes', 'id')],
            'detalles.*.planta_ubicacion_id' => ['required', 'integer', Rule::exists('planta_ubicaciones', 'id')->whereNull('deleted_at')],
            'detalles.*.estado_disponibilidad' => ['required', Rule::in([
                EstadoDisponibilidad::Disponible->value,
                EstadoDisponibilidad::Retenido->value,
                EstadoDisponibilidad::Rechazado->value,
            ])],
            // En una corrección la magnitud se deriva; en el resto la aporta la
            // persona y debe ser > 0: una línea de cero no ajusta nada.
            'detalles.*.cantidad' => $esConteo
                ? ['nullable', 'numeric']
                : ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'detalles.*.cantidad_conteo' => $esConteo
                ? ['required', 'numeric', 'min:0', 'max:9999999999.9999']
                : ['nullable'],
            'detalles.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * El selector envía insumo, lote y ubicación juntos (`bucket`) cuando se
     * elige un saldo existente; capturarlos por separado permitiría pedir una
     * combinación que no existe. Aquí se descompone en columnas.
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

            if ($bucket !== '' && substr_count($bucket, '|') === 3) {
                [
                    $linea['planta_insumo_id'],
                    $linea['planta_lote_id'],
                    $linea['planta_ubicacion_id'],
                    $linea['estado_disponibilidad'],
                ] = explode('|', $bucket);
            }

            // Fila del formulario que el usuario dejó vacía: no es un error.
            if (blank($linea['planta_insumo_id'] ?? null) || blank($linea['planta_ubicacion_id'] ?? null)) {
                continue;
            }

            if (($linea['planta_lote_id'] ?? null) === '') {
                $linea['planta_lote_id'] = null;
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
            'responsable_user_id' => 'responsable',
            'responsable_nombre' => 'nombre del responsable',
            'detalles' => 'líneas',
            'detalles.*.planta_insumo_id' => 'insumo',
            'detalles.*.planta_lote_id' => 'lote',
            'detalles.*.planta_ubicacion_id' => 'ubicación',
            'detalles.*.estado_disponibilidad' => 'estado de disponibilidad',
            'detalles.*.cantidad' => 'cantidad',
            'detalles.*.cantidad_conteo' => 'cantidad contada',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Un ajuste exige motivo: es el único documento que cambia el saldo sin respaldo externo.',
            'motivo.min' => 'El motivo debe explicar el ajuste: al menos 10 caracteres.',
            'detalles.required' => 'El ajuste necesita al menos una línea.',
            'detalles.min' => 'El ajuste necesita al menos una línea.',
            'detalles.*.cantidad_conteo.required' => 'Una corrección de conteo exige la cantidad contada.',
        ];
    }
}
