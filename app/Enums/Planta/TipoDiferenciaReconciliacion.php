<?php

namespace App\Enums\Planta;

/**
 * En qué se aparta la proyección `planta_existencias` del libro mayor.
 *
 * La distinción importante no es «grave / leve»: es {@see esCorregible()}, es
 * decir, si la diferencia puede repararse reescribiendo SOLO la proyección.
 *
 *  - Las tres primeras sí: el mayor es correcto y la proyección se reconstruye
 *    desde él.
 *  - Las dos últimas NO: el problema está EN EL MAYOR, y el mayor no se toca
 *    jamás. Repararlas exige registrar movimientos nuevos —lo que es una
 *    decisión de negocio, no de mantenimiento—, así que la reconciliación se
 *    limita a delatarlas y a terminar con código de salida distinto de cero.
 */
enum TipoDiferenciaReconciliacion: string
{
    /** El mayor tiene movimientos para el bucket y no existe su fila de saldo. */
    case Faltante = 'faltante';

    /** Hay fila de saldo para un bucket sin un solo movimiento que la respalde. */
    case Sobrante = 'sobrante';

    /** Existen ambas, pero el saldo proyectado no es la suma del mayor. */
    case CantidadDistinta = 'cantidad_distinta';

    /** La suma del MAYOR es negativa: el inventario no admite saldo negativo. */
    case SaldoNegativo = 'saldo_negativo';

    /** Bucket con `planta_traslado_id` incoherente con el tipo de su ubicación. */
    case TrasladoInvalido = 'traslado_invalido';

    public function label(): string
    {
        return match ($this) {
            self::Faltante => 'Fila de saldo faltante',
            self::Sobrante => 'Fila de saldo sin respaldo en el mayor',
            self::CantidadDistinta => 'Saldo distinto de la suma del mayor',
            self::SaldoNegativo => 'Suma del mayor negativa',
            self::TrasladoInvalido => 'Traslado incoherente con la ubicación',
        };
    }

    /**
     * ¿Se arregla reescribiendo únicamente `planta_existencias`?
     *
     * Falso cuando el defecto vive en el mayor: corregirlo requeriría escribir o
     * borrar movimientos, y eso está prohibido para la reconciliación.
     */
    public function esCorregible(): bool
    {
        return match ($this) {
            self::Faltante, self::Sobrante, self::CantidadDistinta => true,
            self::SaldoNegativo, self::TrasladoInvalido => false,
        };
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Faltante, self::Sobrante => 'amber',
            self::CantidadDistinta => 'blue',
            self::SaldoNegativo, self::TrasladoInvalido => 'red',
        };
    }
}
