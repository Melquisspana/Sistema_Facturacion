<?php

namespace App\Enums;

/**
 * ORIGEN OPERATIVO de una nota de crédito por avería: dónde se detectó el producto
 * dañado. Es un dato de trazabilidad —quién lo vio y en qué circunstancia—, NO un valor
 * fiscal: no entra al JSON del MH, no cambia el descuento, la retención ni los totales.
 *
 * Se pide porque las dos situaciones no se resuelven igual en sala. La avería detectada
 * DURANTE UNA ENTREGA se descubre con el pedido en la mano y contra el albarán del día;
 * la de REVISIÓN DE INVENTARIO aparece en una revisión de estante, un día en el que
 * puede no haberse entregado nada. Sin este campo las dos llegaban al mismo formulario
 * sin distinguirse, y después no había forma de reconstruir cuál fue cuál.
 */
enum OrigenAveria: string
{
    /** Detectada al entregar un pedido, con el repartidor presente. */
    case Entrega = 'entrega';

    /** Detectada revisando el producto en sala, sin que haya habido entrega ese día. */
    case InventarioSala = 'inventario_sala';

    public function label(): string
    {
        return match ($this) {
            self::Entrega => 'Durante una entrega',
            self::InventarioSala => 'Revisión de inventario en sala',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Entrega => 'El producto dañado se detectó al entregar el pedido.',
            self::InventarioSala => 'Se detectó revisando el producto en sala, aunque no se haya entregado un pedido ese día.',
        };
    }

    /** @return array<string, string> [valor => label] para selects. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
