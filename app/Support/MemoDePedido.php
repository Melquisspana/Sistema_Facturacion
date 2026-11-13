<?php

namespace App\Support;

use Closure;

/**
 * Guarda en memoria lecturas de caché para no repetirlas dentro del mismo pedido HTTP.
 *
 * Se registra como `scoped` en {@see \App\Providers\AppServiceProvider}: el contenedor lo
 * descarta al terminar cada pedido y, en la cola, entre un trabajo y el siguiente
 * (`forgetScopedInstances`). Así un worker de larga vida nunca arrastra un valor viejo.
 */
final class MemoDePedido
{
    /** @var array<string, mixed> */
    private array $valores = [];

    public static function actual(): self
    {
        return app(self::class);
    }

    public function recordar(string $clave, Closure $calcular): mixed
    {
        if (! array_key_exists($clave, $this->valores)) {
            $this->valores[$clave] = $calcular();
        }

        return $this->valores[$clave];
    }

    public function olvidar(string $clave): void
    {
        unset($this->valores[$clave]);
    }
}
