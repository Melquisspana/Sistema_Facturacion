<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmación de un ajuste.
 *
 * Sin campos: confirmar es un acto sobre el documento tal como está guardado.
 * Que no acepte datos ES parte de la regla — en particular, impide que una
 * petición manipulada aporte `cantidad_sistema` en una corrección de conteo,
 * que es justo el valor que debe leerse bajo bloqueo en el servidor.
 *
 * Todas las condiciones reales las verifica PlantaAjusteService dentro de la
 * transacción y con la fila bloqueada.
 */
class ConfirmarAjusteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.ajustes.crear lo aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
