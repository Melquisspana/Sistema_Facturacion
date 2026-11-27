<?php

namespace Tests\Support;

use App\Services\Alertas\ComprobadorConexion;
use RuntimeException;

/**
 * Prueba de conexión que no abre ningún socket. La suite la usa por defecto con
 * «hay internet»; las pruebas de la red caída la cambian.
 */
class ConexionFalsa implements ComprobadorConexion
{
    public int $llamadas = 0;

    public function __construct(public bool $hay = true, public bool $explota = false) {}

    public function hayInternet(): bool
    {
        $this->llamadas++;

        if ($this->explota) {
            throw new RuntimeException('falla rara de la prueba de conexión');
        }

        return $this->hay;
    }
}
