<?php

namespace App\Enums\Planta;

/**
 * Por qué se escribió una fila del libro mayor de Planta.
 *
 * Los tipos distinguen EXPLÍCITAMENTE el recorrido operativo normal de la
 * compensación por reversión. Nunca se reutiliza un tipo «normal» para deshacer
 * algo: quien lea el mayor debe poder separar lo que pasó físicamente de lo que
 * se corrigió contablemente.
 *
 * El caso que lo hace necesario: reversar un traslado ya recibido se registra
 * con el par simplificado destino -> origen, que se PARECE a un traslado normal
 * en sentido inverso. Se tipa como `reversion_traslado_recepcion`, jamás como
 * `traslado_*`, de modo que «cuánta mercancía viajó realmente de Casa a Fábrica»
 * se responda filtrando los tipos `traslado_*` sin contaminarse con
 * correcciones.
 *
 * INVARIANTE: todo movimiento cuyo tipo {@see esReversion()} lleva
 * `movimiento_revertido_id` apuntando al movimiento original y
 * `transicion = 'reversar'`; todo movimiento que no lo es lleva
 * `movimiento_revertido_id = NULL`.
 */
enum TipoMovimientoPlanta: string
{
    /** Prefijo que marca un movimiento como compensación, no como hecho físico. */
    private const PREFIJO_REVERSION = 'reversion_';

    // --- Recorrido operativo normal ---
    case Recepcion = 'recepcion';
    case TrasladoEnvio = 'traslado_envio';
    case TrasladoRecepcion = 'traslado_recepcion';
    case Ajuste = 'ajuste';
    case CargaInicial = 'carga_inicial';
    case CambioDisponibilidad = 'cambio_disponibilidad';

    // --- Compensaciones por reversión ---
    case ReversionRecepcion = 'reversion_recepcion';
    case ReversionTrasladoEnvio = 'reversion_traslado_envio';
    case ReversionTrasladoRecepcion = 'reversion_traslado_recepcion';
    case ReversionAjuste = 'reversion_ajuste';
    case ReversionCambioDisponibilidad = 'reversion_cambio_disponibilidad';

    public function label(): string
    {
        return match ($this) {
            self::Recepcion => 'Recepción',
            self::TrasladoEnvio => 'Traslado enviado',
            self::TrasladoRecepcion => 'Traslado recibido',
            self::Ajuste => 'Ajuste',
            self::CargaInicial => 'Carga inicial',
            self::CambioDisponibilidad => 'Cambio de disponibilidad',
            self::ReversionRecepcion => 'Reversión de recepción',
            self::ReversionTrasladoEnvio => 'Reversión de envío',
            self::ReversionTrasladoRecepcion => 'Reversión de recepción de traslado',
            self::ReversionAjuste => 'Reversión de ajuste',
            self::ReversionCambioDisponibilidad => 'Reversión de cambio de disponibilidad',
        };
    }

    /**
     * ¿Es una compensación contable en vez de un hecho físico? Se deriva del
     * prefijo del valor a propósito: así un tipo nuevo que empiece por
     * `reversion_` queda cubierto por la invariante sin editar este método.
     */
    public function esReversion(): bool
    {
        return str_starts_with($this->value, self::PREFIJO_REVERSION);
    }

    /** ¿Forma parte del recorrido físico de un traslado (envío o recepción)? */
    public function esTrasladoFisico(): bool
    {
        return in_array($this, [self::TrasladoEnvio, self::TrasladoRecepcion], true);
    }

    /**
     * Tipos que representan movimiento físico real de mercancía entre
     * ubicaciones. Es el filtro que responde «cuánto viajó de verdad».
     *
     * @return array<int, self>
     */
    public static function trasladosFisicos(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->esTrasladoFisico()));
    }

    /** @return array<int, self> Todos los tipos de compensación por reversión. */
    public static function reversiones(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->esReversion()));
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Recepcion => 'green',
            self::TrasladoEnvio => 'blue',
            self::TrasladoRecepcion => 'indigo',
            self::Ajuste => 'amber',
            self::CargaInicial => 'gray',
            self::CambioDisponibilidad => 'amber',
            // Todas las reversiones comparten distintivo visual: son correcciones.
            self::ReversionRecepcion,
            self::ReversionTrasladoEnvio,
            self::ReversionTrasladoRecepcion,
            self::ReversionAjuste,
            self::ReversionCambioDisponibilidad => 'rose',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
