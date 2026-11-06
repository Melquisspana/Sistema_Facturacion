<?php

namespace App\Enums;

/**
 * Estado de un pedido de sala. Por ahora solo registrado o anulado; la pantalla de
 * producción agrega los suyos (la columna es texto, no hace falta migrar).
 */
enum EstadoPedidoSala: string
{
    case Registrado = 'registrado';
    case Anulado = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Registrado => 'Registrado',
            self::Anulado => 'Anulado',
        };
    }

    /** Color de la etiqueta (`x-rutas.estado-badge`). */
    public function color(): string
    {
        return match ($this) {
            self::Registrado => 'green',
            self::Anulado => 'red',
        };
    }

    /** @return array<string, string> [valor => etiqueta] */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
