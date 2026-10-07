<?php

namespace App\Services\Contabilidad;

interface SubidaDrivePaqueteContrato
{
    /** @return array{id: string, webViewLink: string} */
    public function subir(string $ruta, string $nombre, int $anio, int $mes, string $correo): array;
}
