<?php

namespace App\Enums\Cobros;

/**
 * Hasta dónde llegó una SOLICITUD (una presentación de documentos al cliente).
 *
 * `Generada` y `Presentada` son dos hechos distintos y por eso son dos estados: el sistema
 * arma el archivo, pero subirlo al portal lo hace una persona. Descargarlo diez veces no
 * lo presenta ni una. Lo mismo entre `Presentada` y `Recibida`: lo segundo lo dice el
 * cliente, no nosotros.
 *
 * `Corregida` marca la solicitud que fue reemplazada por un reenvío. Sus documentos ya no
 * cuelgan de ella, así que la deuda no queda contada dos veces; la solicitud se conserva
 * porque es lo que efectivamente se entregó ese día.
 */
enum EstadoSolicitudCobro: string
{
    /** El archivo está armado. Nadie declaró haberlo subido. */
    case Generada = 'generada';

    /** Alguien declaró que la subió al portal, con fecha. */
    case Presentada = 'presentada';

    /** El cliente acusó recibo, con su referencia. */
    case Recibida = 'recibida';

    /** Fue reemplazada por otra solicitud que la corrige. */
    case Corregida = 'corregida';

    public function label(): string
    {
        return match ($this) {
            self::Generada => 'Generada',
            self::Presentada => 'Presentada',
            self::Recibida => 'Recibida',
            self::Corregida => 'Corregida',
        };
    }

    public function detalle(): string
    {
        return match ($this) {
            self::Generada => 'El archivo está listo. Descargarlo no significa haberlo presentado.',
            self::Presentada => 'Se declaró la presentación en el portal. Sin acuse del cliente todavía.',
            self::Recibida => 'El cliente acusó recibo y dio su referencia.',
            self::Corregida => 'Reemplazada por un reenvío posterior. Se conserva como constancia.',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::Generada => 'bg-gray-100 text-gray-600',
            self::Presentada => 'bg-amber-100 text-amber-700',
            self::Recibida => 'bg-indigo-100 text-indigo-700',
            self::Corregida => 'bg-rose-100 text-rose-700',
        };
    }

    /** El estado que corresponde a los documentos que lleva una solicitud en este estado. */
    public function presentacionDeDocumentos(): EstadoPresentacionCobro
    {
        return match ($this) {
            self::Generada => EstadoPresentacionCobro::Preparada,
            self::Presentada => EstadoPresentacionCobro::Presentada,
            self::Recibida => EstadoPresentacionCobro::Recibida,
            // Una solicitud corregida ya no representa a nadie: sus documentos volvieron a
            // estar sin presentar, salvo que el reenvío los haya tomado.
            self::Corregida => EstadoPresentacionCobro::SinPresentar,
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
