<?php

namespace App\Enums\Planta;

/**
 * Disponibilidad de un saldo de inventario. Es una DIMENSIÓN DEL BUCKET, no un
 * atributo del lote: el mismo lote en la misma ubicación puede tener a la vez
 * saldo disponible, retenido y rechazado, en filas distintas.
 *
 * Fase 2 implementa el control BÁSICO de disponibilidad:
 *
 *  - una recepción se confirma hacia `retenido` o hacia `disponible`;
 *  - SOLO el saldo `disponible` puede trasladarse o utilizarse;
 *  - el `retenido` existe físicamente pero no está disponible;
 *  - `rechazado` es terminal: conserva trazabilidad y no vuelve a la operación.
 *
 * Lo que Fase 2 NO incluye es la evaluación de calidad detallada (parámetros,
 * análisis, muestreos, certificados). Esto es solo el interruptor operativo.
 *
 * REGLA INVIOLABLE: cambiar de estado NUNCA es un `UPDATE` de esta columna.
 * Como el estado forma parte de la llave única del bucket, «cambiar de estado»
 * es por construcción mover cantidad de un bucket a otro con un par de
 * movimientos compensados que suma cero. {@see transicionesPermitidas()} define
 * qué pares son válidos; el signo lo ponen los movimientos, no este enum.
 */
enum EstadoDisponibilidad: string
{
    case Disponible = 'disponible';
    case Retenido = 'retenido';
    case Rechazado = 'rechazado';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Retenido => 'Retenido',
            self::Rechazado => 'Rechazado',
        };
    }

    /**
     * ¿Este saldo se puede trasladar o consumir? Solo el disponible. El retenido
     * existe físicamente pero está fuera de la operación hasta que se libere.
     */
    public function esUtilizable(): bool
    {
        return $this === self::Disponible;
    }

    /**
     * ¿Es un estado del que ya no se sale? `rechazado` lo es en Fase 2: la
     * mercancía rechazada solo abandona el inventario mediante un ajuste, que
     * es admin-only y exige motivo.
     */
    public function esTerminal(): bool
    {
        return $this->transicionesPermitidas() === [];
    }

    /**
     * Estados a los que puede moverse el saldo desde este, mediante un par de
     * movimientos compensados (documento PlantaCambioDisponibilidad).
     *
     * @return array<int, self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            // Retener: sacar de la operación algo que ya estaba disponible.
            self::Disponible => [self::Retenido],
            // Liberar o rechazar lo que estaba a la espera de revisión.
            self::Retenido => [self::Disponible, self::Rechazado],
            // Terminal: no vuelve a la operación en Fase 2.
            self::Rechazado => [],
        };
    }

    /** ¿Es válido mover saldo de este estado al destino? */
    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesPermitidas(), true);
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Disponible => 'green',
            self::Retenido => 'amber',
            self::Rechazado => 'red',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
