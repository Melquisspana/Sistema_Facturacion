<?php

namespace App\Enums;

/**
 * Estados de una salida de ruta.
 *
 *   planificada --iniciar--> en_curso --finalizar--> finalizada
 *        |                      |
 *        +------cancelar--------+--> cancelada
 *
 * Una salida se PLANIFICA antes de que el vehículo salga (se arma la ruta, se
 * eligen los vendedores y se estima el regreso) y se INICIA cuando efectivamente
 * arranca. Por eso el estado inicial es `planificada` y no `en_curso`: la fecha
 * de inicio es una intención hasta que alguien confirma la salida.
 *
 * Finalizada y cancelada son terminales. La diferencia importa para el historial:
 * una salida cancelada nunca ocurrió, una finalizada sí y sus documentos cuentan.
 */
enum EstadoSalidaRuta: string
{
    case Planificada = 'planificada';
    case EnCurso = 'en_curso';
    case Finalizada = 'finalizada';
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Planificada => 'Planificada',
            self::EnCurso => 'En curso',
            self::Finalizada => 'Finalizada',
            self::Cancelada => 'Cancelada',
        };
    }

    /** ¿Se pueden editar todavía ruta, fechas y vendedores? */
    public function esEditable(): bool
    {
        return $this === self::Planificada || $this === self::EnCurso;
    }

    /** ¿La salida ya terminó (de una forma u otra)? */
    public function esTerminal(): bool
    {
        return $this === self::Finalizada || $this === self::Cancelada;
    }

    /** @return array<int, self> */
    public function siguientesEstados(): array
    {
        return match ($this) {
            self::Planificada => [self::EnCurso, self::Cancelada],
            self::EnCurso => [self::Finalizada, self::Cancelada],
            self::Finalizada, self::Cancelada => [],
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
            self::Planificada => 'gray',
            self::EnCurso => 'amber',
            self::Finalizada => 'green',
            self::Cancelada => 'red',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
