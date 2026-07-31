<?php

namespace App\Http\Requests\Planta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmación de una recepción.
 *
 * No lleva campos: confirmar es un acto sobre el documento tal como está
 * guardado, no un envío de datos nuevos. Existe igualmente como Form Request
 * para dejar explícito que la acción tiene su propia puerta y para poder añadir
 * aquí una confirmación escrita si algún día se pide.
 *
 * Todas las condiciones reales —estado borrador, líneas presentes, ubicación
 * operable, insumos activos, permiso de calidad para el destino retenido— las
 * verifica PlantaRecepcionService dentro de la transacción y con la fila
 * bloqueada. Comprobarlas aquí sería mirar un estado que puede cambiar entre la
 * validación y la escritura.
 */
class ConfirmarRecepcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso planta.recepciones.confirmar lo aplica la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
