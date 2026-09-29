<?php

namespace App\Enums\Cobros;

/**
 * De dónde salió un documento del seguimiento de cobros.
 *
 * Las dos fuentes conviven a propósito: la principal son los CCF/NC que Hacienda ya aceptó
 * en nuestro sistema, y la alterna es la incorporación a mano de lo que sigue llegando por
 * contabilidad o por correo. Lo que NO puede pasar es que el mismo documento entre por las
 * dos y se reclame dos veces; eso lo impide el único sobre el número de control
 * normalizado, no este enum.
 *
 * El origen se conserva porque cambia lo que se puede afirmar: de un documento propio
 * sabemos su sello y su importe fiscal; de uno incorporado sabemos lo que alguien tecleó.
 */
enum OrigenCobroDocumento: string
{
    /** CCF/NC emitido por nosotros y aceptado por Hacienda. */
    case Dte = 'dte';

    /** Incorporado a mano (contabilidad, correo). Sin fila propia en `dtes`. */
    case Externo = 'externo';

    /**
     * Importado del JSON adjunto a un correo ENVIADO (CCF/NC de contabilidad). Tampoco
     * tiene fila en `dtes`; su procedencia vive en `cobro_documento_procedencias`.
     */
    case Gmail = 'gmail';

    public function label(): string
    {
        return match ($this) {
            self::Dte => 'Emitido en el sistema',
            self::Externo => 'Incorporado a mano',
            self::Gmail => 'Importado de correo enviado',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::Dte => 'bg-slate-100 text-slate-600',
            self::Externo => 'bg-purple-100 text-purple-700',
            self::Gmail => 'bg-indigo-100 text-indigo-700',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
