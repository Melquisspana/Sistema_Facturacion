<?php

namespace App\Services\Alertas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Pregunta a servidores públicos por su IP, sin resolver nombres: si alguno contesta,
 * con lo que sea, hay internet. Espera como mucho unos segundos por destino.
 *
 * Va por el facade `Http` a propósito: es la única salida a la red que permite la
 * suite (ReproducibilidadSuiteTest prohíbe sockets y cURL a pelo) y la que el candado
 * de las pruebas puede cerrar. Las dos IP tienen certificado propio, así que el HTTPS
 * se valida sin DNS.
 */
final class ComprobadorConexionHttp implements ComprobadorConexion
{
    /** @param  list<string>  $destinos */
    public function __construct(
        private readonly array $destinos = ['https://1.1.1.1', 'https://8.8.8.8'],
        private readonly int $esperaSegundos = 2,
    ) {}

    public function hayInternet(): bool
    {
        foreach ($this->destinos as $destino) {
            try {
                // Cualquier respuesta, también un 4xx o un 5xx, prueba que hubo conexión.
                Http::connectTimeout($this->esperaSegundos)->timeout($this->esperaSegundos + 1)->head($destino);

                return true;
            } catch (ConnectionException) {
                continue;
            }
        }

        return false;
    }
}
