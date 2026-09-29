<?php

namespace App\Enums\Cobros;

/**
 * Hasta dónde llegó el COBRO de un documento. Eje independiente de la presentación
 * ({@see EstadoPresentacionCobro}).
 *
 * Se DERIVA de los eventos de pago, nunca se escribe a mano: la suma de lo cobrado contra
 * el importe del documento es la que decide. Eso es lo que hace que volver a cargar el
 * mismo archivo no cambie nada, y que un archivo posterior que no menciona el documento no
 * le borre el pago que ya tenía.
 *
 * `Diferencia` no es un error del sistema: es el estado en el que el cliente pagó una
 * cantidad distinta a la facturada y alguien tiene que decidir qué pasó. Llamarlo «pagado»
 * escondería el faltante; llamarlo «pendiente» borraría lo cobrado.
 */
enum EstadoPagoCobro: string
{
    /** Nadie ha informado un solo centavo sobre este documento. */
    case Pendiente = 'pendiente';

    /** Se cobró menos que el total y la diferencia todavía no se explicó. */
    case Parcial = 'parcial';

    /** Lo cobrado coincide con el importe del documento, dentro de la tolerancia. */
    case Pagado = 'pagado';

    /** Lo cobrado no coincide: de más, o de menos con tratamiento explícito pendiente. */
    case Diferencia = 'diferencia';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de pago',
            self::Parcial => 'Pago parcial',
            self::Pagado => 'Pagada',
            self::Diferencia => 'Diferencia',
        };
    }

    public function detalle(): string
    {
        return match ($this) {
            self::Pendiente => 'Ningún archivo de pago la menciona todavía.',
            self::Parcial => 'Se cobró menos que el importe del documento.',
            self::Pagado => 'Lo cobrado coincide con el importe del documento.',
            self::Diferencia => 'Lo cobrado no coincide con el importe. Requiere decisión.',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::Pendiente => 'bg-gray-100 text-gray-600',
            self::Parcial => 'bg-amber-100 text-amber-700',
            self::Pagado => 'bg-green-100 text-green-700',
            self::Diferencia => 'bg-rose-100 text-rose-700',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
