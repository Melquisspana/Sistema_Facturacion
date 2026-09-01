<?php

namespace App\Services\DocumentosRecibidos\Buzon;

/**
 * Una PÁGINA de mensajes de un día, en orden de UID ASCENDENTE.
 *
 * Ascendente y no descendente a propósito: el lector anterior ordenaba de mayor a
 * menor y recortaba al límite, así que lo que se caía por el corte era siempre lo
 * MÁS VIEJO — y como la marca de progreso avanzaba igual, esos correos quedaban del
 * lado ya cubierto y no se leían nunca. Leyendo de menor a mayor, lo que queda
 * afuera es lo más nuevo, que la página siguiente recoge desde `ultimoUid`.
 *
 * `truncada` es la señal que impide declarar el día completo: significa que el
 * límite se alcanzó y quedan UID sin leer por encima de `ultimoUid`.
 */
class PaginaMensajes
{
    /**
     * @param  array<int, array<string, mixed>>  $mensajes  normalizados, UID ascendente
     * @param  bool  $truncada  el límite se alcanzó: hay más UID en este día
     * @param  int|null  $ultimoUid  UID más alto leído en esta página (cursor)
     */
    public function __construct(
        public readonly array $mensajes,
        public readonly bool $truncada,
        public readonly ?int $ultimoUid,
    ) {}

    public static function vacia(): self
    {
        return new self([], false, null);
    }

    public function cantidad(): int
    {
        return count($this->mensajes);
    }
}
