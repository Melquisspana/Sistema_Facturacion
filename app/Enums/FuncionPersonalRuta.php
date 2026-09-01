<?php

namespace App\Enums;

/**
 * Qué sabe hacer una persona en la operación de campo. Son COMBINABLES: la misma persona
 * suele ser vendedor y cobrador, y a veces además responsable de la salida.
 *
 * ─────────────────────────── Por qué no es un «cargo» ───────────────────────────
 *
 * Un cargo es uno y define a la persona. Esto es una LISTA de capacidades y define qué se
 * le puede pedir. La diferencia importa porque la operación real no reparte cargos: en una
 * salida a San Miguel van tres personas y las tres venden, una cobra y otra responde por
 * los papeles al volver.
 *
 * NO son permisos del sistema. Un `vendedor` acá es alguien que sale a vender, tenga o no
 * login; quién puede pulsar qué botón lo decide Spatie con `rutas.*`. Confundirlos haría
 * que dar de alta a un repartidor otorgara accesos.
 *
 * NO fijan a nadie a una ruta, un cliente ni una zona. Cualquiera puede ir a cualquier
 * lado, y los destinos habituales —si algún día existen— serán sugerencias, no reglas.
 */
enum FuncionPersonalRuta: string
{
    /** Vende en la sala y entrega el pedido. */
    case Vendedor = 'vendedor';

    /** Lleva y descarga la mercadería. Puede no vender. */
    case Repartidor = 'repartidor';

    /** Puede quedar a cargo de una salida: reúne los documentos de sus compañeros. */
    case ResponsableSalida = 'responsable_salida';

    /** Cobra en la sala. Se declara aparte porque no todo el que vende cobra. */
    case Cobrador = 'cobrador';

    public function label(): string
    {
        return match ($this) {
            self::Vendedor => 'Vendedor',
            self::Repartidor => 'Repartidor',
            self::ResponsableSalida => 'Responsable de salida',
            self::Cobrador => 'Cobrador',
        };
    }

    public function detalle(): string
    {
        return match ($this) {
            self::Vendedor => 'Sale a vender y entregar pedidos.',
            self::Repartidor => 'Lleva y descarga la mercadería; puede no vender.',
            self::ResponsableSalida => 'Puede quedar a cargo de una salida y reunir los documentos del grupo.',
            self::Cobrador => 'Cobra en la sala.',
        };
    }

    /**
     * Clases del badge, SIN variantes `dark:`, igual que el resto de los enums del
     * proyecto.
     *
     * No es un olvido: el modo oscuro de estas insignias lo resuelven los overrides
     * globales de `resources/css/app.css`, y además Tailwind solo escanea
     * `resources/views` —no `app/`—, así que una clase `dark:` escrita acá podría no
     * llegar nunca al CSS compilado y la insignia saldría con el texto del color del
     * fondo.
     *
     * Sin verde: verde da idea de «correcto», y acá no hay nada correcto ni incorrecto.
     */
    public function clase(): string
    {
        return match ($this) {
            self::Vendedor => 'bg-indigo-100 text-indigo-700',
            self::Repartidor => 'bg-sky-100 text-sky-700',
            self::ResponsableSalida => 'bg-amber-100 text-amber-700',
            self::Cobrador => 'bg-violet-100 text-violet-700',
        };
    }

    /** @return array<int, string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> [valor => label] para checkboxes y selects. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
