<?php

namespace App\Enums\Planta;

/**
 * Estados del documento que libera, rechaza o retiene saldo
 * (control BÁSICO de disponibilidad, Fase 2).
 *
 *   borrador --confirmar--> confirmado --reversar--> reversado
 *      |
 *      +----anular-------> anulado        (solo desde borrador)
 *
 * Mismo ciclo que un ajuste, y por el mismo motivo: confirmar es el acto que
 * emite los movimientos. Aquí no se suma ni resta inventario físico —el par de
 * movimientos suma cero—, pero sí cambia qué parte del saldo es utilizable, así
 * que exige permiso propio (`planta.calidad.gestionar`) y motivo obligatorio.
 *
 * Es un documento aparte de PlantaAjuste a propósito: un ajuste altera la
 * cantidad física y un cambio de disponibilidad no. Mezclarlos haría imposible
 * distinguir «apareció/desapareció mercancía» de «la misma mercancía cambió de
 * situación».
 */
enum EstadoCambioDisponibilidad: string
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

    /** ¿Este estado ya emitió movimientos? */
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
