<?php

namespace App\Exceptions\Rutas;

use RuntimeException;

/**
 * El documento ya está en otra salida ABIERTA. No es un error de programación: es
 * la regla del módulo hablando, y por eso lleva un mensaje que se le puede mostrar
 * tal cual a la persona, diciéndole dónde está el documento en vez de solo negarse.
 *
 * Quitar el documento de la otra salida o moverlo son actos manuales y auditados.
 * El sistema nunca los hace por su cuenta.
 */
class DocumentoYaAsignadoException extends RuntimeException
{
    public function __construct(
        public readonly string $numeroControl,
        public readonly ?int $salidaId = null,
        public readonly ?string $salidaDescripcion = null,
    ) {
        $donde = $salidaDescripcion !== null
            ? " Ya está en la salida {$salidaDescripcion}."
            : '';

        parent::__construct("El documento {$numeroControl} ya pertenece a otra salida abierta.{$donde} Para traerlo, movelo desde esa salida.");
    }
}
