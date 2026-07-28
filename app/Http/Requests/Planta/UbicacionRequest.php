<?php

namespace App\Http\Requests\Planta;

use App\Enums\Planta\TipoUbicacion;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Las ubicaciones sostienen el inventario, así que sus reglas no son cosmética:
 * una ubicación de sistema mal editada podría dejar el tránsito operable a mano
 * o desaparecer del catálogo con saldo dentro. Todo se valida en BACKEND; los
 * campos deshabilitados en el formulario son solo una ayuda visual.
 */
class UbicacionRequest extends FormRequest
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
            'codigo' => [
                'required', 'string', 'max:20',
                Rule::unique('planta_ubicaciones', 'codigo')->ignore($this->route('ubicacion')),
            ],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', Rule::enum(TipoUbicacion::class)],
            'es_sistema' => ['required', 'boolean'],
            'permite_operacion_manual' => ['required', 'boolean'],
            'activo' => ['required', 'boolean'],
            'orden' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validarTransito($validator),
            fn (Validator $validator) => $this->validarUbicacionDeSistema($validator),
        ];
    }

    /**
     * El saldo en tránsito solo lo mueven los traslados al enviarse y recibirse.
     * Permitir operación manual sobre él dejaría que una recepción o un ajuste
     * inventaran mercancía «en camino» sin ningún traslado detrás.
     */
    private function validarTransito(Validator $validator): void
    {
        $tipo = TipoUbicacion::tryFrom((string) $this->input('tipo'));

        if ($tipo === TipoUbicacion::Transito && $this->boolean('permite_operacion_manual')) {
            $validator->errors()->add(
                'permite_operacion_manual',
                'Una ubicación de tránsito no admite operación manual: su saldo solo lo mueven los traslados.'
            );
        }
    }

    /**
     * Reglas sobre una ubicación que YA es de sistema. No dependen de códigos
     * concretos (CASA/FABRICA/TRANSITO): dependen solo de la bandera, que es lo
     * que el seeder marcará más adelante.
     */
    private function validarUbicacionDeSistema(Validator $validator): void
    {
        $ubicacion = $this->route('ubicacion');

        if (! $ubicacion instanceof PlantaUbicacion || ! $ubicacion->es_sistema) {
            return;
        }

        // El código de una ubicación de sistema es su identidad: el seeder y el
        // resto del módulo la localizan por él.
        if ((string) $this->input('codigo') !== $ubicacion->codigo) {
            $validator->errors()->add(
                'codigo',
                'No se puede cambiar el código de una ubicación de sistema.'
            );
        }

        // Desactivarla la sacaría del catálogo conservando su saldo dentro.
        if (! $this->boolean('activo')) {
            $validator->errors()->add(
                'activo',
                'No se puede desactivar una ubicación de sistema.'
            );
        }

        // Si se pudiera quitar la bandera, las dos reglas anteriores se
        // esquivarían en dos peticiones: quitar `es_sistema` y luego desactivar.
        if (! $this->boolean('es_sistema')) {
            $validator->errors()->add(
                'es_sistema',
                'No se puede quitar la marca de sistema a una ubicación que ya la tiene.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'es_sistema' => 'es de sistema',
            'permite_operacion_manual' => 'permite operación manual',
        ];
    }
}
