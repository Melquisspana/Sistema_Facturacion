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
            // Vigente: en uno borrado no se emite.
            'establecimiento_id' => ['required', Rule::exists('establecimientos', 'id')->whereNull('deleted_at')],
            'codigo' => [
                'required', 'string', 'max:4',
                // Como lo acepta Hacienda en el número de control (DTE-03-M001P001-…).
                'regex:/^P[0-9]{3}$/',
                // La base tampoco deja repetir el de uno borrado: al crearlo se recupera ese
                // ({@see PuntoVentaController::store()}), y al editar otro se avisa abajo.
                Rule::unique('puntos_venta', 'codigo')
                    ->where(fn ($q) => $q->where('establecimiento_id', $this->establecimiento_id))
                    ->withoutTrashed()
                    ->ignore($puntoVentaId),
            ],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'establecimiento_id.exists' => 'El establecimiento no existe o fue eliminado.',
            'codigo.regex' => 'El código tiene que ser P y tres números, como P001: así lo pide Hacienda en el número de control.',
            'codigo.unique' => 'Ese código ya lo tiene otro punto de venta de este establecimiento.',
        ];
    }

    /**
     * El código va en el número de control de cada documento, y los correlativos buscan
     * el punto de venta junto con su establecimiento: con documentos numerados no cambia el
     * código, y con correlativos o cualquier documento (borradores incluidos) no se pasa a
     * otro establecimiento.
     */
    public function withValidator(Validator $validator): void
    {
        $puntoVenta = $this->route('puntoVenta');
        if (! $puntoVenta instanceof PuntoVenta) {
            return;
        }

        $validator->after(function ($validator) use ($puntoVenta) {
            if (! $validator->errors()->has('codigo') && PuntoVenta::onlyTrashed()
                ->where('establecimiento_id', $this->input('establecimiento_id'))
                ->where('codigo', $this->input('codigo'))
                ->exists()) {
                $validator->errors()->add('codigo', 'Ese código era de un punto de venta que se borró. Para volver a usarlo, creá un punto de venta con ese código: se recupera el que se borró.');
            }

            if ((string) $this->input('codigo') !== (string) $puntoVenta->codigo && $puntoVenta->tieneDocumentos(soloConNumero: true)) {
                $validator->errors()->add('codigo', 'Este punto de venta ya tiene facturas: el código no se puede cambiar, porque va en el número de control de cada una.');
            }

            if ((string) $this->input('establecimiento_id') !== (string) $puntoVenta->establecimiento_id
                && ($puntoVenta->correlativos()->exists() || $puntoVenta->tieneDocumentos())) {
                $validator->errors()->add('establecimiento_id', 'Este punto de venta ya tiene correlativos, facturas o borradores: no se puede pasar a otro establecimiento.');
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
