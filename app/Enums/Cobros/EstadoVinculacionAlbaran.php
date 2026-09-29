<?php

namespace App\Enums\Cobros;

/**
 * En qué situación está el ALBARÁN de un documento de cobro.
 *
 * `Revisar` es el estado que justifica todo este enum. Sin él, una coincidencia dudosa
 * tiene que resolverse eligiendo entre dos mentiras: vincular el albarán equivocado, o
 * decir que no hay ninguno. Las dos se descubren tarde y caro —la primera presenta una
 * factura con el albarán de otra sala; la segunda deja la factura fuera de la solicitud—.
 *
 * Por eso vincular es una afirmación fuerte: se reserva para la coincidencia ÚNICA y sin
 * contradicciones. Todo lo demás queda a la vista con su motivo y sus candidatos.
 */
enum EstadoVinculacionAlbaran: string
{
    /** No se encontró ningún albarán que pueda corresponder. */
    case SinAlbaran = 'sin_albaran';

    /** Un único albarán coincide y nada lo contradice. */
    case Vinculado = 'vinculado';

    /** Hay candidatos, pero ninguno gana solo. Queda para una persona, con el motivo. */
    case Revisar = 'revisar';

    public function label(): string
    {
        return match ($this) {
            self::SinAlbaran => 'Sin albarán',
            self::Vinculado => 'Albarán vinculado',
            self::Revisar => 'Revisar vinculación',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::SinAlbaran => 'bg-gray-100 text-gray-600',
            self::Vinculado => 'bg-green-100 text-green-700',
            self::Revisar => 'bg-amber-100 text-amber-800',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
