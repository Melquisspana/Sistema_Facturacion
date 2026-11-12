<?php

namespace Tests\Support;

use App\Enums\RolEnSalida;
use App\Models\SalidaRuta;

/**
 * Para armar en una prueba una salida de antes del 10/11/2026 con su gente. Ya nadie arma
 * salidas en el sistema (el viaje suma solo a quien saca el pedido o entrega); las pruebas de
 * las reglas que siguen valiendo para las salidas viejas las arman con esto.
 */
final class SalidaDePrueba
{
    /**
     * Deja la salida exactamente con estas personas; `$responsableId`, si viene, la encabeza.
     *
     * @param  array<int, int|string>  $personalIds
     */
    public static function participantes(SalidaRuta $salida, array $personalIds, ?int $responsableId): void
    {
        $salida->participantes()->delete();

        foreach (array_unique(array_map('intval', $personalIds)) as $id) {
            $salida->participantes()->create([
                'rutas_personal_id' => $id,
                'rol' => $id === $responsableId ? RolEnSalida::Responsable : RolEnSalida::Acompanante,
            ]);
        }
    }
}
