<?php

namespace App\Http\Requests\Dte;

use App\Enums\TipoImpuesto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de entrada para actualizar una línea del borrador.
 *
 * Flujo normal: solo se actualiza la CANTIDAD (entero positivo). El descuento por
 * línea no se captura manualmente; el precio es snapshot. La validación combinada
 * (cantidad entera, descuento ≤ importe) la realiza el servicio con
 * {@see AgregarLineaDteRequest::validarValores()} sobre la mezcla con la línea.
 */
class ActualizarLineaDteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tipos = array_map(fn (TipoImpuesto $t) => $t->value, TipoImpuesto::cases());

        return [
            'cantidad' => ['sometimes', 'integer', 'min:1'],
            'tipo_impuesto' => ['sometimes', Rule::in($tipos)],
        ];
    }
}
