<?php

namespace App\Enums\Planta;

/**
 * Estados de un traslado entre ubicaciones (Casa <-> Fábrica).
 *
 *   borrador --enviar--> enviado --recibir--> recibido
 *      |                    |                    |
 *      +--cancelar-->    reversar             reversar
 *         cancelado     (-> reversado)       (-> reversado)
 *
 * Enviar y recibir son dos actos distintos (salida física y llegada física) y
 * pueden recaer en personas distintas. Entre ambos, el saldo vive en la
 * ubicación de tránsito, atado al id de ESTE traslado, de modo que dos envíos
 * simultáneos del mismo insumo y lote nunca se mezclan.
 *
 * NO existe la recepción parcial: se recibe exactamente lo enviado, y las
 * diferencias se registran después con un ajuste con motivo.
 */
enum EstadoTrasladoPlanta: string
{
    case Borrador = 'borrador';
    case Enviado = 'enviado';
    case Recibido = 'recibido';
    case Cancelado = 'cancelado';
    case Reversado = 'reversado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviado => 'En tránsito',
            self::Recibido => 'Recibido',
            self::Cancelado => 'Cancelado',
            self::Reversado => 'Reversado',
        };
    }

    /** Único estado en el que se pueden editar cabecera y líneas. */
    public function esEditable(): bool
    {
        return $this === self::Borrador;
    }

    /** ¿El saldo de este traslado está ahora mismo en la ubicación de tránsito? */
    public function estaEnTransito(): bool
    {
        return $this === self::Enviado;
    }

    /** @return array<int, self> */
    public function siguientesEstados(): array
    {
        return match ($this) {
            self::Borrador => [self::Enviado, self::Cancelado],
            self::Enviado => [self::Recibido, self::Reversado],
            self::Recibido => [self::Reversado],
            self::Cancelado => [],
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
            self::Enviado => 'amber',
            self::Recibido => 'green',
            self::Cancelado => 'red',
            self::Reversado => 'rose',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
