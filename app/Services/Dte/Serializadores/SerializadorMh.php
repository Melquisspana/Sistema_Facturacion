<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;

/**
 * Contrato común de los serializadores oficiales del MH: toman DteSalidaData
 * (interno, ya calculado) y devuelven el array con los nombres oficiales del
 * schema de su tipo. No recalculan impuestos, no firman, no transmiten.
 */
interface SerializadorMh
{
    /**
     * @return array<string, mixed>
     *
     * @throws \App\Exceptions\Dte\DteNoSerializableException
     */
    public function serializar(DteSalidaData $d): array;
}
