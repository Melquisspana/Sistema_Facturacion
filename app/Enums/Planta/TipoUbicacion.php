<?php

namespace App\Enums\Planta;

/**
 * Naturaleza de una ubicación de inventario.
 *
 *  - `fisica`: un lugar real donde se puede recibir, ajustar y contar (Casa,
 *    Fábrica de Olocuilta).
 *  - `transito`: ubicación de SISTEMA que sostiene el saldo que ya salió del
 *    origen pero todavía no llegó al destino. No admite operación manual: solo
 *    la tocan los traslados al enviarse y recibirse.
 *
 * La distinción es una invariante del inventario, no una etiqueta: un saldo en
 * tránsito lleva `planta_traslado_id > 0` y un saldo físico lo lleva en 0
 * (invariante I1 del plan). {@see esTransito()} es lo que consulta el servicio.
 */
enum TipoUbicacion: string
{
    case Fisica = 'fisica';
    case Transito = 'transito';

    public function label(): string
    {
        return match ($this) {
            self::Fisica => 'Física',
            self::Transito => 'En tránsito',
        };
    }

    /**
     * ¿Es la ubicación de tránsito? Un bucket en esta ubicación DEBE llevar
     * traslado; uno en ubicación física DEBE llevar 0.
     */
    public function esTransito(): bool
    {
        return $this === self::Transito;
    }

    /**
     * ¿Admite recepciones, ajustes y conteos manuales? El tránsito no: su saldo
     * solo lo mueven los traslados.
     */
    public function permiteOperacionManual(): bool
    {
        return $this === self::Fisica;
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Fisica => 'green',
            self::Transito => 'amber',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
