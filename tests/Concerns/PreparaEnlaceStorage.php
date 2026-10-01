<?php

namespace Tests\Concerns;

/**
 * Prepara public/storage en checkouts nuevos y CI sin storage:link.
 * Nunca toca un enlace existente; solo limpia el directorio vacío que creó la prueba.
 */
trait PreparaEnlaceStorage
{
    protected function asegurarEnlaceDeStorage(): void
    {
        $ruta = public_path('storage');

        if (file_exists($ruta) || is_link($ruta)) {
            return;
        }

        if (! mkdir($ruta)) {
            throw new \RuntimeException('No se pudo preparar public/storage para la prueba.');
        }

        $this->beforeApplicationDestroyed(function () use ($ruta) {
            if (! is_link($ruta) && is_dir($ruta) && scandir($ruta) === ['.', '..']) {
                rmdir($ruta);
            }
        });
    }
}
