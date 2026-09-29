<?php

namespace App\Enums\Cobros;

/**
 * Hasta dónde llegó un documento en el circuito de PRESENTACIÓN al cliente.
 *
 * Es un eje distinto del PAGO ({@see EstadoPagoCobro}) y no se mezcla con él: una factura
 * puede estar recibida y sin pagar, o pagada sin haberse presentado nunca por acá. Con una
 * sola columna de «estado» habría que elegir cuál de las dos verdades se pierde.
 *
 * Los cuatro estados son HECHOS, no suposiciones. En particular:
 *
 *  · `Preparada` significa que el archivo existe. NO que se haya subido al portal: eso lo
 *    hace una persona fuera del sistema y por eso `Presentada` es un registro explícito.
 *  · `Recibida` solo lo pone la evidencia del cliente —su correo de RECIBIDO o una captura
 *    a mano—, nunca el paso del tiempo.
 */
enum EstadoPresentacionCobro: string
{
    /** Aceptada por Hacienda y todavía fuera de cualquier solicitud. */
    case SinPresentar = 'sin_presentar';

    /** Entró en una solicitud y el archivo está armado; nadie confirmó haberlo subido. */
    case Preparada = 'preparada';

    /** Alguien declaró que subió el archivo al portal. Sin respuesta del cliente aún. */
    case Presentada = 'presentada';

    /** El cliente acusó recibo, con su referencia. */
    case Recibida = 'recibida';

    public function label(): string
    {
        return match ($this) {
            self::SinPresentar => 'Sin presentar',
            self::Preparada => 'Archivo preparado',
            self::Presentada => 'Presentada',
            self::Recibida => 'Recibida',
        };
    }

    /** Qué significa exactamente, y qué NO significa. Se muestra como ayuda en pantalla. */
    public function detalle(): string
    {
        return match ($this) {
            self::SinPresentar => 'Todavía no entró en ninguna solicitud.',
            self::Preparada => 'El archivo está armado. Nadie ha confirmado que se subiera al portal.',
            self::Presentada => 'Se declaró que el archivo se subió al portal. Calleja no ha acusado recibo.',
            self::Recibida => 'Calleja acusó recibo de la solicitud que la llevaba.',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::SinPresentar => 'bg-gray-100 text-gray-600',
            self::Preparada => 'bg-sky-100 text-sky-700',
            self::Presentada => 'bg-amber-100 text-amber-700',
            self::Recibida => 'bg-indigo-100 text-indigo-700',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
