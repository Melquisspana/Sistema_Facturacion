<?php

namespace App\Http\Requests\Fabrica;

/**
 * Lo marcado en la hoja «Qué va», que se guarda solo con cada cambio. Las mismas reglas de
 * forma que al mandar, sin clave, versión ni casilla: guardar el borrador no crea un envío.
 */
class BorradorPedidoRequest extends MandarPedidoRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_diff_key(parent::rules(), array_flip(['clave', 'version', 'sigue']));
    }
}
