<?php

namespace App\Enums\Planta;

/**
 * Estados de un ajuste de inventario (carga inicial, merma, daño, vencimiento,
 * corrección de conteo).
 *
 *   borrador --confirmar--> confirmado --reversar--> reversado
 *      |
 *      +----anular-------> anulado        (solo desde borrador)
 *
 * `crear` y `confirmar` están separados a propósito: confirmar es el acto que
 * MUEVE inventario. En Fase 2 ambas acciones son admin-only, y la separación
 * permite que en el futuro un supervisor prepare el ajuste y otro lo confirme
 * sin rediseñar nada.
 *
 * El motivo es obligatorio siempre, en cualquier tipo de ajuste.
 */
enum EstadoAjustePlanta: string
{
    case Borrador = 'borrador';
    case Confirmado = 'confirmado';
    case Anulado = 'anulado';
    case Reversado = 'reversado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Confirmado => 'Confirmado',
            self::Anulado => 'Anulado',
            self::Reversado => 'Reversado',
        };
    }

    /** Único estado en el que se pueden editar cabecera y líneas. */
    public function esEditable(): bool
    {
        return $this === self::Borrador;
    }

    /** ¿Este estado ya movió inventario? */
    public function moviInventario(): bool
    {
        return in_array($this, [self::Confirmado, self::Reversado], true);
    }

    /** @return array<int, self> */
    public function siguientesEstados(): array
    {
        return match ($this) {
            self::Borrador => [self::Confirmado, self::Anulado],
            self::Confirmado => [self::Reversado],
            self::Anulado => [],
            self::Reversado => [],
        };
    }

    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->siguientesEstados(), true);
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Confirmado => 'green',
            self::Anulado => 'red',
            self::Reversado => 'rose',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
