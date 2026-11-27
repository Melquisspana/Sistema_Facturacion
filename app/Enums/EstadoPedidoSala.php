<?php

namespace App\Enums;

use App\Models\PedidoSala;
use App\Services\Rutas\PedidosFabrica;

/**
 * Estado de un pedido de sala: una sola máquina, en la misma columna de texto (los estados
 * de la fábrica entraron sin migrar). La regla de cada paso vive en
 * {@see PedidosFabrica}.
 *
 *  - registrado («Pendiente»): falta mandarlo; sin envíos vigentes.
 *  - parcial («Mandado en parte»): salió algo y lo que falta va en otro envío.
 *  - listo («Listo para salir», 24/11/2026; igual en la fábrica y en la oficina desde el
 *    25/11/2026): la caja quedó como va a salir, aunque falte algo que no hay; espera el
 *    próximo viaje. Lo marcan a mano en la PC de pedidos, que guarda ahí lo que hay en la caja,
 *    y se quita con otro toque: vuelve a pendiente o a mandado en parte según sus envíos.
 *    Quién y cuándo, en la auditoría. Puede ser el resto de un envío anterior
 *    ({@see PedidoSala::enParte()}).
 *  - enviado («Mandado»): cerrado; salió todo, o salió una parte y lo que falta ya no va.
 *  - no_se_trae: cerrado sin envíos vigentes.
 *  - anulado: solo lo anula el vendedor.
 *  - volvio («Volvió a la fábrica», 27/11/2026): salió, la sala no lo recibió y la mercadería
 *    volvió. Lo marca la oficina desde «Llegó de pedidos»; sus envíos quedan anulados y el
 *    pedido, cerrado para siempre: no vuelve a pendiente ni a la fábrica (si la sala lo
 *    quiere, se saca otro). Quién, cuándo y por qué, en la auditoría
 *    ({@see PedidosFabrica::volvioALaFabrica()}).
 *
 * «Vigente» es todo menos anulado; «por mandar» es registrado, parcial o listo.
 */
enum EstadoPedidoSala: string
{
    case Registrado = 'registrado';
    case Parcial = 'parcial';
    case Listo = 'listo';
    case Enviado = 'enviado';
    case NoSeTrae = 'no_se_trae';
    case Anulado = 'anulado';
    case Volvio = 'volvio';

    public function label(): string
    {
        return match ($this) {
            self::Registrado => 'Pendiente',
            self::Parcial => 'Mandado en parte',
            self::Listo => 'Listo para salir',
            self::Enviado => 'Mandado',
            self::NoSeTrae => 'No se trae',
            self::Anulado => 'Anulado',
            self::Volvio => 'Volvió a la fábrica',
        };
    }

    /** Color de la etiqueta (`x-rutas.estado-badge`): solo los que ya pinta. */
    public function color(): string
    {
        return match ($this) {
            self::Registrado, self::Parcial, self::Listo => 'amber',
            self::Enviado => 'green',
            // Cerrado sin entregar, como lo que no se trae.
            self::NoSeTrae, self::Volvio => 'gray',
            self::Anulado => 'red',
        };
    }

    /** ¿Le falta salir de la fábrica? Lo que volvió, no: no se manda otra vez. */
    public function porMandar(): bool
    {
        return $this === self::Registrado || $this === self::Parcial || $this === self::Listo;
    }

    /**
     * ¿Cerrado? Lo que no salió ya no va: solo acá la hoja marca la «F» de faltó. Lo que volvió
     * a la fábrica también está cerrado, pero no entra: no se entregó nada, no hay «F» que marcar.
     */
    public function cerrado(): bool
    {
        return $this === self::Enviado || $this === self::NoSeTrae;
    }

    /**
     * ¿Tiene hoja para imprimir? Salió algo o ya se cerró (no si está pendiente o anulado). El
     * listo que es resto de un envío también: {@see PedidoSala::tieneHoja()}. Lo que volvió a la
     * fábrica no: sus envíos están anulados y la hoja no tendría nada que decir.
     */
    public function tieneHoja(): bool
    {
        return $this === self::Parcial || $this->cerrado();
    }

    /** @return list<string> */
    public static function valoresPorMandar(): array
    {
        return [self::Registrado->value, self::Parcial->value, self::Listo->value];
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
