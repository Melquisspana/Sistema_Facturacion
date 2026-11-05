<?php

namespace App\Http\Requests\Rutas;

use App\Models\PedidoSala;
use App\Services\Rutas\PedidosSala;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El pedido de una sala, desde el celular. El formulario manda una cantidad por producto
 * (`cantidades[producto_id]`); las que quedan vacías no se pidieron. Quién puede guardarlo,
 * en qué salida y en qué sala lo decide {@see PedidosSala}.
 *
 * La OC es opcional y su forma solo avisa (#169): no se valida acá.
 */
class PedidoSalaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lineas = [];
        foreach ((array) $this->input('cantidades', []) as $producto => $cantidad) {
            $cantidad = is_string($cantidad) ? trim($cantidad) : $cantidad;
            if ($cantidad !== null && $cantidad !== '') {
                $lineas[] = ['producto_id' => $producto, 'cantidad' => $cantidad];
            }
        }
        $this->merge(['lineas' => $lineas]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lineas' => ['required', 'array', 'min:1', 'max:500'],
            'lineas.*.producto_id' => ['required', 'integer', 'distinct',
                Rule::exists('productos', 'id')->where('activo', true)->whereNull('deleted_at')],
            'lineas.*.cantidad' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,4})?$/', 'gt:0', 'max:'.PedidoSala::CANTIDAD_MAXIMA],
            'numero_orden_compra' => ['nullable', 'string', 'max:30'],
            'notas' => ['nullable', 'string', 'max:500'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.PedidoSala::FOTO_MAX_KB],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lineas.required' => 'Anotá al menos un producto con su cantidad.',
            'lineas.min' => 'Anotá al menos un producto con su cantidad.',
            'lineas.*.producto_id.exists' => 'Uno de los productos no existe o ya no está activo. Revisá la lista.',
            'lineas.*.producto_id.*' => 'Uno de los productos no es válido. Revisá la lista.',
            'lineas.*.cantidad.gt' => 'Cada cantidad tiene que ser mayor que cero.',
            'lineas.*.cantidad.max' => 'Cada cantidad puede ser hasta '.number_format(PedidoSala::CANTIDAD_MAXIMA).'.',
            'lineas.*.cantidad.*' => 'Cada cantidad tiene que ser un número mayor que cero, con hasta 4 decimales.',
            'numero_orden_compra.max' => 'El número de orden de compra puede tener hasta 30 caracteres.',
            'notas.max' => 'Las notas pueden tener hasta 500 caracteres.',
            'foto.image' => 'La foto tiene que ser una imagen (JPG, PNG o WebP).',
            'foto.mimes' => 'La foto tiene que ser una imagen (JPG, PNG o WebP).',
            'foto.max' => 'La foto puede pesar hasta 10 MB.',
            'foto.*' => 'No se pudo subir la foto. Tomala de nuevo.',
        ];
    }
}
