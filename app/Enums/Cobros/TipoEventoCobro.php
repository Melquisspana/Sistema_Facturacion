<?php

namespace App\Enums\Cobros;

/**
 * Qué clase de HECHO registra un evento de cobro.
 *
 * La bitácora guarda hechos con su evidencia, y los acumulados del documento se derivan de
 * ella. Por eso el tipo importa: un `Pago` suma al cobrado y una `Observacion` no, aunque
 * las dos lleguen en el mismo correo.
 */
enum TipoEventoCobro: string
{
    /** El documento entró en una solicitud y alguien declaró haberla presentado. */
    case Presentacion = 'presentacion';

    /** El cliente acusó recibo de la solicitud que lo llevaba. */
    case Recibido = 'recibido';

    /** El cliente observó el documento. No cambia el pago; no se pierde al actualizarlo. */
    case Observacion = 'observacion';

    /** Un archivo de pagos informó dinero cobrado sobre este documento. */
    case Pago = 'pago';

    /** Ajuste informado por el cliente (los QD del TXT) imputado a este documento. */
    case Ajuste = 'ajuste';

    /** Se deshizo un pago o un ajuste anterior, con motivo. */
    case Reversion = 'reversion';

    /** Nota interna. No afecta ningún acumulado. */
    case Nota = 'nota';

    /** Una persona eligió a mano el albarán del documento (con el anterior y el nuevo). */
    case Vinculacion = 'vinculacion';

    public function label(): string
    {
        return match ($this) {
            self::Presentacion => 'Presentación',
            self::Recibido => 'Recibido por el cliente',
            self::Observacion => 'Observación del cliente',
            self::Pago => 'Pago informado',
            self::Ajuste => 'Ajuste informado',
            self::Reversion => 'Reversión',
            self::Nota => 'Nota interna',
            self::Vinculacion => 'Vinculación manual de albarán',
        };
    }

    /** ¿Este evento mueve el importe cobrado del documento? */
    public function afectaCobrado(): bool
    {
        return in_array($this, [self::Pago, self::Ajuste, self::Reversion], true);
    }
}
