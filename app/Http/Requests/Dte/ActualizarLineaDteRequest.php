<?php

namespace App\Http\Requests\Dte;

use App\Enums\TipoDte;
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
 *
 * Excepción: una línea de Factura de Exportación SIN producto de catálogo (FEX
 * libre, copiada desde una Lista de Empaque o agregada manualmente) admite además
 * editar descripción, unidad, precio por caja y descuento — no tiene snapshot de
 * catálogo que proteger. No cambia nada para CCF/NC/Factura ni para líneas FEX
 * con producto de catálogo.
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

        $reglas = [
            'cantidad' => ['sometimes', 'integer', 'min:1'],
            'tipo_impuesto' => ['sometimes', Rule::in($tipos)],
        ];

        if ($this->esLineaLibreDeFex()) {
            $reglas['descripcion'] = ['sometimes', 'string', 'max:1000'];
            $reglas['unidad_codigo'] = ['sometimes', 'string', 'max:3'];
            $reglas['precio_unitario'] = ['sometimes', 'numeric', 'min:0'];
            $reglas['descuento_monto'] = ['sometimes', 'numeric', 'min:0'];
        }

        return $reglas;
    }

    private function esLineaLibreDeFex(): bool
    {
        $linea = $this->route('linea');

        return $linea
            && $linea->producto_id === null
            && $linea->dte?->tipo_dte === TipoDte::FacturaExportacion;
    }
}
