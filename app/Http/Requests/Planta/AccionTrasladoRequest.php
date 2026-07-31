<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Enviar o recibir un traslado.
 *
 * Sin campos, y eso ES la regla de negocio: se recibe exactamente lo que se
 * envió. Si esta petición aceptara cantidades, lotes o destino, existiría la
 * recepción parcial —y con ella la imposibilidad de distinguir un error de
 * conteo de una pérdida real, más saldo huérfano en tránsito para siempre—.
 *
 * Todas las condiciones reales —estado, ubicaciones operables, existencia del
 * tránsito y saldo suficiente en el bucket exacto— las verifica
 * PlantaTrasladoService dentro de la transacción y con la fila bloqueada.
 */
class AccionTrasladoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos enviar/recibir los aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
