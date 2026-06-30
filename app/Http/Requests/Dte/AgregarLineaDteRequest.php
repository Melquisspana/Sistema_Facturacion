<?php

namespace App\Http\Requests\Dte;

use App\Support\Dinero;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;

/**
 * Validación de entrada para agregar una línea al borrador desde un producto.
 *
 * {@see validarValores()} encapsula la validación de cantidad/precio/descuento
 * (incluida la regla "descuento ≤ importe") para que el servicio la reutilice
 * tras resolver el precio efectivo (override o precio del producto).
 */
class AgregarLineaDteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            // Cantidad: entero positivo (estos productos no se venden fraccionados).
            'cantidad' => ['required', 'integer', 'min:1'],
            'precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'descuento_monto' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validarDescuentoContraImporte(
                $validator,
                $this->input('cantidad'),
                $this->input('precio_unitario'),
                $this->input('descuento_monto', 0),
            );
        });
    }

    /**
     * Valida cantidad/precio/descuento de una línea de forma independiente del
     * ciclo HTTP. Lanza ValidationException si algo no cumple.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validarValores(
        string|int|float $cantidad,
        string|int|float $precioUnitario,
        string|int|float $descuento,
    ): void {
        $validator = ValidatorFactory::make(
            [
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'descuento_monto' => $descuento,
            ],
            [
                'cantidad' => ['required', 'integer', 'min:1'],
                'precio_unitario' => ['required', 'numeric', 'min:0'],
                'descuento_monto' => ['required', 'numeric', 'min:0'],
            ],
            [
                'cantidad.integer' => 'La cantidad debe ser un número entero.',
                'cantidad.min' => 'La cantidad debe ser al menos 1.',
            ],
        );

        $validator->after(function (Validator $validator) use ($cantidad, $precioUnitario, $descuento) {
            (new self())->validarDescuentoContraImporte($validator, $cantidad, $precioUnitario, $descuento);
        });

        $validator->validate();
    }

    /**
     * El descuento de línea no puede exceder el importe bruto (cantidad × precio).
     */
    private function validarDescuentoContraImporte(
        Validator $validator,
        string|int|float|null $cantidad,
        string|int|float|null $precio,
        string|int|float|null $descuento,
    ): void {
        if (! is_numeric($cantidad) || ! is_numeric($precio) || ! is_numeric($descuento)) {
            return; // las reglas de tipo ya reportan el problema base.
        }

        $importe = Dinero::multiplicar($cantidad, $precio);
        if (Dinero::comparar(Dinero::de($descuento), $importe) > 0) {
            $validator->errors()->add(
                'descuento_monto',
                'El descuento no puede ser mayor que el importe de la línea (cantidad × precio).'
            );
        }
    }
}
