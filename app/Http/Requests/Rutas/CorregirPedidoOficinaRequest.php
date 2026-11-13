<?php

namespace App\Http\Requests\Rutas;

use App\Models\PedidoSala;
use App\Services\Rutas\PedidosOficina;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La oficina corrige un pedido (13/11/2026): la sala y una cantidad por línea
 * (`cantidades[linea_id]`), con las mismas reglas de cantidad que al sacar el pedido
 * ({@see PedidoSalaRequest}), más el motivo y la versión del formulario. Quién puede, lo ya
 * mandado y la sala nueva los revisa {@see PedidosOficina}.
 */
class CorregirPedidoOficinaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('corregirEnOficina', $this->route('pedido'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'size:64'],
            'motivo' => ['required', 'string', 'max:500'],
            'cliente_sucursal_id' => ['nullable', 'integer'],
            'cantidades' => ['nullable', 'array', 'max:500'],
            // 0 quita un producto que no se mandó (13/11/2026); lo demás lo revisa PedidosOficina.
            'cantidades.*' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,4})?$/', 'min:0', 'max:'.PedidoSala::CANTIDAD_MAXIMA],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'version.*' => 'Volvé a abrir el pedido y corregilo de nuevo.',
            'motivo.required' => 'Escribí por qué se corrige.',
            'motivo.max' => 'El motivo puede tener hasta 500 caracteres.',
            'cliente_sucursal_id.*' => 'Elegí una sala de la lista.',
            'cantidades.*.max' => 'Cada cantidad puede ser hasta '.number_format(PedidoSala::CANTIDAD_MAXIMA).'.',
            'cantidades.*.*' => 'Cada cantidad tiene que ser un número, con hasta 4 decimales. Para quitar un producto que no se mandó, poné 0.',
        ];
    }
}
