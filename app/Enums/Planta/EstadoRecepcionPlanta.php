<?php

namespace App\Enums\Planta;

/**
 * Estados de una recepción de insumos.
 *
 *   borrador --confirmar--> confirmada --reversar--> reversada
 *      |
 *      +----anular--------> anulada        (solo desde borrador)
 *
 * Diferencias que NO deben confundirse:
 *
 *  - «anular» descarta un borrador que nunca tocó el inventario;
 *  - «reversar» deshace contablemente una recepción ya confirmada, creando un
 *    documento nuevo con movimientos espejo (el original jamás se borra ni se
 *    edita);
 *  - rechazar mercancía por calidad NO es ninguna de las dos: es un cambio de
 *    disponibilidad ({@see EstadoDisponibilidad}) y la recepción sigue
 *    confirmada.
 *
 * La máquina de transiciones se aplicará en el servicio del documento, dentro
 * de la transacción y tras tomar el lock. Aquí solo vive el vocabulario.
 */
enum EstadoRecepcionPlanta: string
{
    case Borrador = 'borrador';
    case Confirmada = 'confirmada';
    case Anulada = 'anulada';
    case Reversada = 'reversada';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Confirmada => 'Confirmada',
            self::Anulada => 'Anulada',
            self::Reversada => 'Reversada',
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
        return in_array($this, [self::Confirmada, self::Reversada], true);
    }

    /** @return array<int, self> */
    public function siguientesEstados(): array
    {
        return match ($this) {
            self::Borrador => [self::Confirmada, self::Anulada],
            self::Confirmada => [self::Reversada],
            self::Anulada => [],
            self::Reversada => [],
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
            self::Confirmada => 'green',
            self::Anulada => 'red',
            self::Reversada => 'rose',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
