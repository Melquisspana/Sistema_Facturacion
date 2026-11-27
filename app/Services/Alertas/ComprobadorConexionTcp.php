<?php

namespace App\Services\Alertas;

/**
 * Abre una conexión TCP a servidores públicos por su IP, sin resolver nombres: si
 * alguno contesta, hay internet. Espera como mucho unos segundos por destino y no
 * manda nada; la conexión se cierra apenas se abre.
 */
final class ComprobadorConexionTcp implements ComprobadorConexion
{
    /** @param  list<string>  $destinos  ip:puerto */
    public function __construct(
        private readonly array $destinos = ['1.1.1.1:443', '8.8.8.8:443'],
        private readonly float $esperaSegundos = 2.0,
    ) {}

    public function hayInternet(): bool
    {
        foreach ($this->destinos as $destino) {
            $conexion = @stream_socket_client('tcp://'.$destino, $codigo, $mensaje, $this->esperaSegundos);

            if ($conexion !== false) {
                fclose($conexion);

                return true;
            }
        }

        return false;
    }
}
