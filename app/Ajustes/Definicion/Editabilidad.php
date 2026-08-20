<?php

namespace App\Ajustes\Definicion;

/**
 * Si el ajuste puede escribirse HOY desde la aplicación.
 *
 * `Futura` es deliberadamente distinta de `SoloLectura`: marca claves que el
 * registry ya conoce y clasifica (ambiente fiscal, interruptores de firma y
 * transmisión) pero que en esta fase NO se abren a la UI. Sirve para poder
 * mostrarlas y auditarlas sin habilitar todavía el cambio.
 */
enum Editabilidad: string
{
    case Editable = 'editable';
    case Futura = 'futura';
    case SoloLectura = 'solo_lectura';

    public function permiteEscritura(): bool
    {
        return $this === self::Editable;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Editable => 'Editable',
            self::Futura => 'Editable en una fase futura',
            self::SoloLectura => 'Solo lectura',
        };
    }
}
