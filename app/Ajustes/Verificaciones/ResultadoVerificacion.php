<?php

namespace App\Ajustes\Verificaciones;

/** Desenlace de una comprobación de configuración. Solo dos: funcionó o no. */
enum ResultadoVerificacion: string
{
    case Exito = 'exito';
    case Fallo = 'fallo';

    public function esExito(): bool
    {
        return $this === self::Exito;
    }

    public function etiqueta(): string
    {
        return $this === self::Exito ? 'Correcto' : 'Con error';
    }
}
