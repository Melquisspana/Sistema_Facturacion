<?php

namespace App\Enums;

/**
 * Por qué cambió el estado de cobro de un renglón de PPQ.
 *
 * Solo hay dos formas legítimas de que un documento pase de «pendiente» a «pagado» o al
 * revés, y conviene que la bitácora las distinga porque tienen dueños distintos:
 *
 *   · TXT       — lo dijo el archivo de pagos del cliente. Es evidencia externa.
 *   · REVERSIÓN — lo decidió una persona de la casa, con motivo y con permiso.
 *
 * Sin esta distinción, una corrección hecha a mano se vería igual que un pago reportado
 * por el cliente, y el día que un abono no cuadre no habría forma de saber cuál de las
 * dos cosas pasó.
 */
enum OrigenConciliacionPpq: string
{
    /** Procesamiento de un archivo TXT de pagos del cliente. */
    case Txt = 'txt';

    /** Corrección explícita hecha por una persona autorizada. */
    case Reversion = 'reversion';

    public function label(): string
    {
        return match ($this) {
            self::Txt => 'Archivo de pagos',
            self::Reversion => 'Corrección manual',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::Txt => 'bg-indigo-100 text-indigo-700',
            self::Reversion => 'bg-amber-100 text-amber-700',
        };
    }
}
