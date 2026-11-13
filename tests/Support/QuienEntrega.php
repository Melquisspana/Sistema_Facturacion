<?php

namespace Tests\Support;

use App\Models\PersonalRuta;

/**
 * Desde el 12/11/2026 «Por entregar» no trae a nadie elegido: el formulario manda siempre
 * `entregado_por_id` y el servidor lo exige. Las pruebas que marcan como el vendedor que tiene
 * el celular lo mandan con esto: su propia ficha, salvo que la prueba ya mande otro.
 */
final class QuienEntrega
{
    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public static function yo(array $datos): array
    {
        $persona = PersonalRuta::where('user_id', auth()->id())->where('activo', true)->value('id');

        return $persona === null ? $datos : $datos + ['entregado_por_id' => $persona];
    }
}
