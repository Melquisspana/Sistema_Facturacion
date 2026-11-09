<?php

namespace App\Http\Requests\Fabrica;

use App\Models\PedidoSala;
use App\Services\Rutas\PedidosFabrica;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que la fábrica manda de un pedido: una cantidad por línea (`cantidades[linea_id]`), el
 * motivo opcional de lo que no va, la clave del envío (contra el doble toque) y la versión
 * del pedido que se estaba mirando. Acá se valida la FORMA; que cada línea sea del pedido,
 * que no se mande de más y quién puede, lo decide {@see PedidosFabrica}.
 *
 * `authorize()` devuelve true, como PedidoSalaRequest: el candado real es el middleware de
 * la ruta (`permission:fabrica.pedidos.mandar`) más el Gate dentro del servicio.
 */
class MandarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Vacío es 0: «Nada» deja la casilla en 0 o vacía. */
    protected function prepareForValidation(): void
    {
        $cantidades = $this->input('cantidades');
        if (is_array($cantidades)) {
            foreach ($cantidades as $linea => $cantidad) {
                $cantidad = is_string($cantidad) ? trim($cantidad) : $cantidad;
                $cantidades[$linea] = ($cantidad === null || $cantidad === '') ? '0' : $cantidad;
            }
            $this->merge(['cantidades' => $cantidades]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clave' => ['required', 'uuid'],
            'version' => ['required', 'string', 'size:64'],
            'cantidades' => ['required', 'array', 'min:1', 'max:500'],
            'cantidades.*' => ['nullable', 'numeric', 'regex:/^\d+(?:\.\d{1,4})?$/', 'min:0', 'max:'.PedidoSala::CANTIDAD_MAXIMA],
            'motivos' => ['nullable', 'array', 'max:500'],
            'motivos.*' => ['nullable', 'string', 'max:200'],
            'sigue' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $vieja = 'Esta pantalla quedó vieja. Volvé a abrir el pedido.';
        $numero = 'Cada cantidad tiene que ser un número de 0 en adelante, con hasta 4 decimales.';

        return [
            'clave.required' => $vieja,
            'clave.uuid' => $vieja,
            'version.required' => $vieja,
            'version.string' => $vieja,
            'version.size' => $vieja,
            'cantidades.required' => 'No marcaste nada para mandar.',
            'cantidades.array' => 'Revisá las cantidades.',
            'cantidades.min' => 'No marcaste nada para mandar.',
            'cantidades.max' => 'Revisá las cantidades.',
            'cantidades.*.numeric' => $numero,
            'cantidades.*.regex' => $numero,
            'cantidades.*.min' => $numero,
            'cantidades.*.max' => 'Cada cantidad puede ser hasta '.number_format(PedidoSala::CANTIDAD_MAXIMA).'.',
            'motivos.array' => 'Revisá los motivos.',
            'motivos.max' => 'Revisá los motivos.',
            'motivos.*.string' => 'Revisá los motivos.',
            'motivos.*.max' => 'El motivo puede tener hasta 200 caracteres.',
        ];
    }
}
