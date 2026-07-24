<?php

namespace App\Http\Requests\Dte;

use App\Models\Dte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autoriza la reversión TOTAL de un CCF con una Nota de Crédito por devolución.
 *
 * La autorización real vive en la política ({@see \App\Policies\DtePolicy::revertirConNotaCredito()}):
 * exige el permiso operativo `dte.gestionar` (no perfiles de solo lectura) y que el
 * documento sea un CCF ACEPTADO REALMENTE por Hacienda. Toda la lógica de negocio
 * (herencias, saldo, transacción) la ejecuta DteBorradorService::revertirCcfCompleto();
 * este request no recibe campos (la reversión siempre cubre el CCF completo).
 */
class RevertirConNotaCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dte = $this->route('dte');

        return $dte instanceof Dte
            && $this->user() !== null
            && $this->user()->can('revertirConNotaCredito', $dte);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
