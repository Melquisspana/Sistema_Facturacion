<?php

namespace App\Enums;

/**
 * Estados de un lote de Prontos Pagos (PPQ).
 *
 * Flujo operativo: borrador -> listo -> enviado -> pagado, con 'observado' como
 * desvío cuando Calleja devuelve observaciones. Es un flujo de gestión de cobro,
 * independiente del ciclo de vida fiscal del DTE ([[EstadoDte]]).
 */
enum EstadoPpq: string
{
    case Borrador = 'borrador';
    case Listo = 'listo';
    case Enviado = 'enviado';
    case Pagado = 'pagado';
    case Observado = 'observado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Listo => 'Listo',
            self::Enviado => 'Enviado',
            self::Pagado => 'Pagado',
            self::Observado => 'Observado',
        };
    }

    /** El lote solo se puede editar (agregar/quitar items) en borrador o listo. */
    public function esEditable(): bool
    {
        return in_array($this, [self::Borrador, self::Listo], true);
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Listo => 'blue',
            self::Enviado => 'amber',
            self::Pagado => 'green',
            self::Observado => 'red',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
