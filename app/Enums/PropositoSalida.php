<?php

namespace App\Enums;

/**
 * Para qué sale una salida de ruta (docs/PLAN_TABLERO_RUTAS.md, etapa 3). Primero se saca
 * el pedido y se entrega 1 o 2 días después; a veces se hacen las dos cosas en la misma
 * salida. Todas las salidas anteriores son de entregar.
 */
enum PropositoSalida: string
{
    case Entregar = 'entregar';
    case Pedido = 'pedido';
    case Ambos = 'ambos';

    public function label(): string
    {
        return match ($this) {
            self::Entregar => 'Entregar',
            self::Pedido => 'Sacar pedido',
            self::Ambos => 'Las dos',
        };
    }

    /** Cómo se lee en la hoja y en «Mi salida». */
    public function descripcion(): string
    {
        return match ($this) {
            self::Entregar => 'Para entregar',
            self::Pedido => 'Para sacar pedido',
            self::Ambos => 'Para entregar y sacar pedido',
        };
    }

    /** ¿Lleva CCF para entregar? */
    public function entrega(): bool
    {
        return $this !== self::Pedido;
    }

    /** ¿Se saca pedido en las salas? */
    public function sacaPedido(): bool
    {
        return $this !== self::Entregar;
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
