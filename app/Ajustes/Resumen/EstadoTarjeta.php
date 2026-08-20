<?php

namespace App\Ajustes\Resumen;

/**
 * Estado de una tarjeta del Resumen. Vocabulario CERRADO a propósito: en cuanto
 * cada tarjeta inventa su propio rótulo, la pantalla deja de poder leerse de un
 * vistazo, que es lo único que un resumen tiene que conseguir.
 *
 * `SoloLectura` no es un estado bueno ni malo: dice "esto se administra fuera de
 * aquí". Se usa donde el sistema conoce el valor pero esta pantalla no lo cambia
 * (ambiente fiscal, firmador, credenciales de Hacienda).
 */
enum EstadoTarjeta: string
{
    case Configurado = 'configurado';
    case NoConfigurado = 'no_configurado';
    case Activo = 'activo';
    case Desactivado = 'desactivado';
    case Advertencia = 'advertencia';
    case Error = 'error';
    case SoloLectura = 'solo_lectura';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Configurado => 'Configurado',
            self::NoConfigurado => 'No configurado',
            self::Activo => 'Activo',
            self::Desactivado => 'Desactivado',
            self::Advertencia => 'Advertencia',
            self::Error => 'Error',
            self::SoloLectura => 'Solo lectura',
        };
    }

    /**
     * Clases del badge. Se devuelven desde el enum y no desde la vista para que
     * los siete estados se pinten igual en cualquier pantalla que los use.
     * Colores de la paleta estándar: el tema oscuro los reteme en app.css.
     */
    public function clases(): string
    {
        return match ($this) {
            self::Configurado, self::Activo => 'bg-green-100 text-green-800',
            self::NoConfigurado, self::Desactivado => 'bg-gray-100 text-gray-600',
            self::Advertencia => 'bg-amber-100 text-amber-800',
            self::Error => 'bg-red-100 text-red-700',
            self::SoloLectura => 'bg-blue-100 text-blue-700',
        };
    }

    /** ¿Pide atención? Ordena el Resumen para que lo urgente quede arriba. */
    public function requiereAtencion(): bool
    {
        return $this === self::Error || $this === self::Advertencia;
    }
}
