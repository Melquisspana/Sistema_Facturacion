<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmación de un cambio de disponibilidad.
 *
 * Sin campos: confirmar es un acto sobre el documento tal como está guardado, no
 * un envío de datos nuevos. Existe como Form Request para dejar explícito que la
 * acción tiene su propia puerta.
 *
 * Todas las condiciones reales —estado borrador, cantidad positiva, ubicación
 * operable, transición permitida y saldo retenido suficiente— las verifica
 * PlantaCambioDisponibilidadService dentro de la transacción y con la fila
 * bloqueada. Comprobarlas aquí sería mirar un estado que puede cambiar entre la
 * validación y la escritura.
 */
class ConfirmarCambioDisponibilidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.calidad.gestionar lo aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
