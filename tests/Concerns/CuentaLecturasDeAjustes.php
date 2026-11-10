<?php

namespace Tests\Concerns;

use App\Support\Sala;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Support\Facades\Event;

/**
 * Cuenta cuántas veces una acción consulta los ajustes del sistema. Cada lectura de un
 * ajuste pide la huella `ajustes:huella` a la caché, que en producción es una consulta a
 * la tabla `cache`: una pantalla que lee un ajuste por fila la paga por cada fila.
 */
trait CuentaLecturasDeAjustes
{
    private int $lecturasDeAjustes = 0;

    private bool $escuchaLecturasDeAjustes = false;

    private function lecturasDeAjustes(callable $accion): int
    {
        if (! $this->escuchaLecturasDeAjustes) {
            Event::listen(RetrievingKey::class, function (RetrievingKey $evento): void {
                if ($evento->key === 'ajustes:huella') {
                    $this->lecturasDeAjustes++;
                }
            });
            $this->escuchaLecturasDeAjustes = true;
        }

        // El nombre de cada sala se recuerda en el proceso: sin esto, la segunda medición
        // saldría más barata que la primera por un motivo ajeno a lo que se mide.
        Sala::olvidarCache();

        $antes = $this->lecturasDeAjustes;
        $accion();

        return $this->lecturasDeAjustes - $antes;
    }
}
