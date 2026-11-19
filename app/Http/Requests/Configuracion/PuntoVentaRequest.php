<?php

namespace App\Http\Requests\Configuracion;

use App\Models\PuntoVenta;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PuntoVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $puntoVentaId = $this->route('puntoVenta')?->id;

        return [
            'establecimiento_id' => ['required', 'exists:establecimientos,id'],
            'codigo' => [
                'required', 'string', 'max:4',
                Rule::unique('puntos_venta', 'codigo')
                    ->where(fn ($q) => $q->where('establecimiento_id', $this->establecimiento_id))
                    ->whereNull('deleted_at')
                    ->ignore($puntoVentaId),
            ],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['required', 'boolean'],
        ];
    }

    /**
     * El código va en el número de control de cada documento, y los correlativos buscan
     * el punto de venta junto con su establecimiento: con documentos no cambia el código,
     * y con correlativos o documentos no se pasa a otro establecimiento.
     */
    public function withValidator(Validator $validator): void
    {
        $puntoVenta = $this->route('puntoVenta');
        if (! $puntoVenta instanceof PuntoVenta) {
            return;
        }

        $validator->after(function ($validator) use ($puntoVenta) {
            if ((string) $this->input('codigo') !== (string) $puntoVenta->codigo && $puntoVenta->tieneDocumentos()) {
                $validator->errors()->add('codigo', 'Este punto de venta ya tiene facturas: el código no se puede cambiar, porque va en el número de control de cada una.');
            }

            if ((string) $this->input('establecimiento_id') !== (string) $puntoVenta->establecimiento_id
                && ($puntoVenta->correlativos()->exists() || $puntoVenta->tieneDocumentos())) {
                $validator->errors()->add('establecimiento_id', 'Este punto de venta ya tiene correlativos o facturas: no se puede pasar a otro establecimiento.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
        ]);
    }
}
