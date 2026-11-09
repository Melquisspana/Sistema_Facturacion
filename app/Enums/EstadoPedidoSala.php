<?php

namespace App\Enums;

use App\Services\Rutas\PedidosFabrica;

/**
 * Estado de un pedido de sala: una sola máquina, en la misma columna de texto (los estados
 * de la fábrica entraron sin migrar). La regla de cada paso vive en
 * {@see PedidosFabrica}.
 *
 *  - registrado («Pendiente»): falta mandarlo; sin envíos vigentes.
 *  - parcial («Mandado en parte»): salió algo y lo que falta va en otro envío.
 *  - enviado («Mandado»): cerrado; salió todo, o salió una parte y lo que falta ya no va.
 *  - no_se_trae: cerrado sin envíos vigentes.
 *  - anulado: solo lo anula el vendedor.
 *
 * «Vigente» es todo menos anulado; «por mandar» es registrado o parcial.
 */
enum EstadoPedidoSala: string
{
    case Registrado = 'registrado';
    case Parcial = 'parcial';
    case Enviado = 'enviado';
    case NoSeTrae = 'no_se_trae';
    case Anulado = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Registrado => 'Pendiente',
            self::Parcial => 'Mandado en parte',
            self::Enviado => 'Mandado',
            self::NoSeTrae => 'No se trae',
            self::Anulado => 'Anulado',
        };
    }

    /** Color de la etiqueta (`x-rutas.estado-badge`): solo los que ya pinta. */
    public function color(): string
    {
        return match ($this) {
            self::Registrado, self::Parcial => 'amber',
            self::Enviado => 'green',
            self::NoSeTrae => 'gray',
            self::Anulado => 'red',
        };
    }

    /** ¿Le falta salir de la fábrica? */
    public function porMandar(): bool
    {
        return $this === self::Registrado || $this === self::Parcial;
    }

    /** ¿Cerrado? Lo que no salió ya no va: solo acá la hoja marca la «F» de faltó. */
    public function cerrado(): bool
    {
        return $this === self::Enviado || $this === self::NoSeTrae;
    }

    /** ¿Tiene hoja para imprimir? Salió algo o ya se cerró (no si está pendiente o anulado). */
    public function tieneHoja(): bool
    {
        return $this === self::Parcial || $this->cerrado();
    }

    /** @return list<string> */
    public static function valoresPorMandar(): array
    {
        return [self::Registrado->value, self::Parcial->value];
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
